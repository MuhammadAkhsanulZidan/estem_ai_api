import sys
import os
import argparse
import json
import pickle

def main():
    parser = argparse.ArgumentParser(description="Predict query intent.")
    parser.add_argument("--query", required=True, help="User search query")
    args = parser.parse_args()
    
    query = args.query.lower().strip()
    model_path = os.path.join(os.path.dirname(__file__), "intent_model.pkl")
    
    # Fallback if model doesn't exist
    if not os.path.exists(model_path):
        print(json.dumps({"intent": "general_search", "confidence": 1.0}))
        sys.exit(0)
        
    try:
        with open(model_path, 'rb') as f:
            vectorizer, classifier = pickle.load(f)
            
        X = vectorizer.transform([query])
        prediction = classifier.predict(X)[0]
        
        # Get probability/confidence
        probabilities = classifier.predict_proba(X)[0]
        class_idx = list(classifier.classes_).index(prediction)
        confidence = float(probabilities[class_idx])
        
        print(json.dumps({"intent": prediction, "confidence": confidence}))
        
    except Exception as e:
        print(json.dumps({"intent": "general_search", "confidence": 0.0, "error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
