import sys
import argparse
import json
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory

# Initialize Sastrawi Stemmer
factory = StemmerFactory()
stemmer = factory.create_stemmer()

# Basic Indonesian stopwords to remove
STOPWORDS = {
    "yang", "di", "dan", "itu", "ini", "untuk", "dengan", "ke", "dari", 
    "adalah", "oleh", "pada", "juga", "atau", "saya", "kami", "anda",
    "mereka", "kita", "dia", "dalam", "bisa", "dapat", "sudah", "belum",
    "akan", "telah", "ingin", "ada", "sebagai", "untuk", "tentang", "adalah"
}

def clean_and_stem(query_text):
    # Convert to lowercase and split into words
    words = query_text.lower().split()
    # Filter stopwords
    filtered_words = [w for w in words if w not in STOPWORDS]
    # Rejoin and stem
    cleaned_text = " ".join(filtered_words)
    stemmed_text = stemmer.stem(cleaned_text)
    return stemmed_text

def main():
    parser = argparse.ArgumentParser(description="Stem Indonesian query for FTS matching.")
    parser.add_argument("--query", required=True, help="User search query")
    args = parser.parse_args()

    try:
        stemmed_query = clean_and_stem(args.query)
        print(json.dumps({"success": True, "stemmed_query": stemmed_query}))
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
