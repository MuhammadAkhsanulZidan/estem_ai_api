import os
import sys
import argparse
import json
from pypdf import PdfReader
from docx import Document
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory

# Initialize Sastrawi Stemmer
factory = StemmerFactory()
stemmer = factory.create_stemmer()

def should_skip_text(text):
    text_lower = text.lower().strip()
    
    # 1. Skip Table of Contents / lists
    if "daftar isi" in text_lower or "table of contents" in text_lower:
        return True
    if "daftar gambar" in text_lower or "daftar tabel" in text_lower:
        return True
        
    # Skip if contains table of contents style leaders (e.g. "Bab I .......... 5")
    if text_lower.count("...") > 3 or text_lower.count("..") > 6:
        return True

    # 2. Skip Appendices / Lampiran pages
    if text_lower.startswith("lampiran") or text_lower.startswith("appendix"):
        return True
        
    return False

def chunk_text(text, limit=800, overlap=150):
    chunks = []
    start = 0
    while start < len(text):
        end = start + limit
        chunk = text[start:end]
        chunks.append(chunk)
        start += (limit - overlap)
    return chunks

def extract_pdf(file_path):
    pages = []
    reader = PdfReader(file_path)
    for idx, page in enumerate(reader.pages):
        text = page.extract_text()
        if text:
            if should_skip_text(text):
                continue
            pages.append((idx + 1, text))
    return pages

def extract_docx(file_path):
    doc = Document(file_path)
    full_text = []
    for para in doc.paragraphs:
        if should_skip_text(para.text):
            continue
        full_text.append(para.text)
    return [(1, "\n".join(full_text))]

def extract_txt(file_path):
    with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
        text = f.read()
    return [(1, text)]


def main():
    parser = argparse.ArgumentParser(description="Parse PDF/DOCX/TXT and output chunked, stemmed Indonesian text.")
    parser.add_argument("--file", required=True, help="Path to the document file")
    args = parser.parse_args()

    file_path = args.file
    if not os.path.exists(file_path):
        print(json.dumps({"error": f"File not found: {file_path}"}))
        sys.exit(1)

    ext = os.path.splitext(file_path)[1].lower()
    
    try:
        if ext == ".pdf":
            pages = extract_pdf(file_path)
        elif ext == ".docx":
            pages = extract_docx(file_path)
        elif ext in [".txt", ".md"]:
            pages = extract_txt(file_path)
        else:
            print(json.dumps({"error": f"Unsupported file extension: {ext}"}))
            sys.exit(1)
            
        # Load intent classifier model if available
        model_path = os.path.join(os.path.dirname(__file__), "intent_model.pkl")
        vectorizer, classifier = None, None
        if os.path.exists(model_path):
            try:
                import pickle
                with open(model_path, 'rb') as f:
                    vectorizer, classifier = pickle.load(f)
            except Exception:
                pass

        output_chunks = []
        for page_num, text in pages:
            # Chunk the page text
            text_chunks = chunk_text(text)
            for idx, chunk in enumerate(text_chunks):
                if not chunk.strip() or should_skip_text(chunk):
                    continue
                # Stem the chunk for search index
                stemmed_content = stemmer.stem(chunk)
                
                # Predict intent
                intent = "general_search"
                if vectorizer and classifier:
                    try:
                        X = vectorizer.transform([chunk.lower().strip()])
                        intent = classifier.predict(X)[0]
                    except Exception:
                        pass
                
                output_chunks.append({
                    "page_number": page_num,
                    "chunk_index": idx,
                    "content": chunk.strip(),
                    "intent": intent,
                    "stemmed": stemmed_content
                })

        if output_chunks:
            from sentence_transformers import SentenceTransformer
            model = SentenceTransformer('paraphrase-multilingual-MiniLM-L12-v2')
            texts = [c['content'] for c in output_chunks]
            embeddings = model.encode(texts).tolist()
            for idx, emb in enumerate(embeddings):
                output_chunks[idx]['embedding'] = emb
                
        print(json.dumps({"success": True, "chunks": output_chunks}))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
