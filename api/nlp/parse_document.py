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
            pages.append((idx + 1, text))
    return pages

def extract_docx(file_path):
    doc = Document(file_path)
    full_text = []
    for para in doc.paragraphs:
        full_text.append(para.text)
    # Return as a single page (Page 1) since DOCX doesn't have fixed pages easily
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
            
        output_chunks = []
        for page_num, text in pages:
            # Chunk the page text
            text_chunks = chunk_text(text)
            for idx, chunk in enumerate(text_chunks):
                if not chunk.strip():
                    continue
                # Stem the chunk for search index
                stemmed_content = stemmer.stem(chunk)
                output_chunks.append({
                    "page_number": page_num,
                    "chunk_index": idx,
                    "content": chunk.strip(),
                    "stemmed": stemmed_content
                })
                
        print(json.dumps({"success": True, "chunks": output_chunks}))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
