import os
import sys
import argparse
import json
import numpy as np
import psycopg2
from sentence_transformers import SentenceTransformer

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

        # Fetch all document chunks and their embeddings
        query = """
            SELECT c.id, c.content, c.page_number, d.file_name, c.embedding
            FROM chatbot_document_chunks c
            JOIN chatbot_documents d ON c.document_id = d.id
            WHERE c.embedding IS NOT NULL
        """
        params = []
        if args.intent:
            query += " AND c.intent = %s"
            params.append(args.intent)
            
        cursor.execute(query, params)
        rows = cursor.fetchall()

        if not rows:
            print(json.dumps({"success": True, "matches": []}))
            sys.exit(0)

        # Initialize Model and encode query
        model = SentenceTransformer('paraphrase-multilingual-MiniLM-L12-v2')
        query_vector = model.encode(args.query)

        # Calculate cosine similarity for each chunk
        matches = []
        norm_q = np.linalg.norm(query_vector)
        
        for row in rows:
            chunk_id, content, page_num, file_name, emb_list = row
            if not emb_list:
                continue
            
            emb = np.array(emb_list)
            dot_prod = np.dot(query_vector, emb)
            norm_e = np.linalg.norm(emb)
            
            similarity = float(dot_prod / (norm_q * norm_e)) if (norm_q * norm_e) > 0 else 0.0
            
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
