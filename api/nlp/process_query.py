import os
import sys

# Set HuggingFace Cache Directories to local project directory before importing
os.environ['HF_HOME'] = os.path.join(os.path.dirname(__file__), '.cache')
os.environ['SENTENCE_TRANSFORMERS_HOME'] = os.path.join(os.path.dirname(__file__), '.cache')

import argparse
import json
import numpy as np
import psycopg2
from sentence_transformers import SentenceTransformer
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory

def load_env(env_path):
    env_vars = {}
    if os.path.exists(env_path):
        with open(env_path, 'r') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    k, v = line.split('=', 1)
                    env_vars[k.strip()] = v.strip()
    return env_vars

def main():
    parser = argparse.ArgumentParser(description="Semantic search Indonesian query.")
    parser.add_argument("--query", required=True, help="User search query")
    parser.add_argument("--intent", required=False, default=None, help="Optional intent classification to filter chunks")
    parser.add_argument("--file", required=False, default=None, help="Optional specific file name filter")
    args = parser.parse_args()

    try:
        # Load environment variables
        env_path = os.path.join(os.path.dirname(__file__), "..", ".env")
        env = load_env(env_path)
        
        db_host = env.get("DB_HOST", "127.0.0.1")
        db_port = env.get("DB_PORT", "5432")
        db_name = env.get("DB_NAME", "")
        db_user = env.get("DB_USER", "")
        db_pass = env.get("DB_PASS", "")

        # Connect to Database
        conn = psycopg2.connect(
            host=db_host,
            port=db_port,
            database=db_name,
            user=db_user,
            password=db_pass
        )
        cursor = conn.cursor()

        # Initialize Sastrawi and stem query for FTS
        factory = StemmerFactory()
        stemmer = factory.create_stemmer()
        stemmed_query = stemmer.stem(args.query)
        
        # Build logical OR query for to_tsquery (e.g. 'erti | secretome')
        ts_query = ' | '.join(word for word in stemmed_query.split() if word)
        if not ts_query:
            ts_query = 'dummy'

        # Fetch all document chunks, their embeddings, and FTS match boolean
        query = """
            SELECT c.id, c.content, c.page_number, d.file_name, c.embedding, c.intent,
                   (c.search_vector @@ to_tsquery('simple', %s)) as is_fts_match
            FROM chatbot_document_chunks c
            JOIN chatbot_documents d ON c.document_id = d.id
            WHERE c.embedding IS NOT NULL
        """
        params = [ts_query]
        if args.file:
            query += " AND d.file_name = %s"
            params.append(args.file)
            
        cursor.execute(query, params)
        rows = cursor.fetchall()

        if not rows:
            print(json.dumps({"success": True, "matches": []}))
            sys.exit(0)

        # Initialize Model and encode query
        model = SentenceTransformer('paraphrase-multilingual-MiniLM-L12-v2')
        query_vector = model.encode(args.query)

        # Calculate similarity scores for each chunk
        matches = []
        norm_q = np.linalg.norm(query_vector)
        
        for row in rows:
            chunk_id, content, page_num, file_name, emb_list, chunk_intent, is_fts_match = row
            if not emb_list:
                continue
            
            # Skip extremely short chunks that contain no real clinical information (dilution/garbage bias)
            if len(content.strip()) < 80:
                continue
            
            emb = np.array(emb_list)
            dot_prod = np.dot(query_vector, emb)
            norm_e = np.linalg.norm(emb)
            
            similarity = float(dot_prod / (norm_q * norm_e)) if (norm_q * norm_e) > 0 else 0.0
            
            # Boost score if the chunk intent matches the predicted intent
            if args.intent and chunk_intent == args.intent:
                similarity = min(1.0, similarity + 0.05)
                
            # Apply a heavy boost (+0.25) if it matches the keyword FTS search
            if is_fts_match:
                similarity = min(1.0, similarity + 0.25)
            
            matches.append({
                "content": content,
                "page_number": page_num,
                "file_name": file_name,
                "score": similarity
            })

        # Sort matches by score desc
        matches.sort(key=lambda x: x['score'], reverse=True)
        
        # Take top 3
        top_matches = matches[:3]
        
        print(json.dumps({"success": True, "matches": top_matches}))

    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
