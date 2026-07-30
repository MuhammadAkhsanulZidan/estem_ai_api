import sys
import json
import pickle
import os
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression

def main():
    # Read training data from stdin JSON
    try:
        input_data = sys.stdin.read()
        if not input_data.strip():
            print(json.dumps({"error": "No training data provided via stdin"}))
            sys.exit(1)
            
        data = json.loads(input_data)
        
        phrases = []
        labels = []
        for item in data:
            phrases.append(item['phrase'].lower())
            labels.append(item['intent'])
            
        if len(phrases) < 2:
            print(json.dumps({"error": "Need at least 2 training examples to train model"}))
            sys.exit(1)

        # Train TF-IDF + Logistic Regression
        vectorizer = TfidfVectorizer(ngram_range=(1, 2))
        X = vectorizer.fit_transform(phrases)
        
        classifier = LogisticRegression(max_iter=1000)
        classifier.fit(X, labels)
        
        # Save model
        model_path = os.path.join(os.path.dirname(__file__), "intent_model.pkl")
        with open(model_path, 'wb') as f:
            pickle.dump((vectorizer, classifier), f)
            
        print(json.dumps({"success": True, "message": f"Model trained with {len(phrases)} phrases"}))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
