<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Endpoint Tester</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background-color: #0f172a;
            color: #f8fafc;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 900px;
            background: #1e293b;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            border: 1px solid #334155;
        }

        h1 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: #38bdf8;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .input-group {
            display: flex;
            gap: 10px;
        }

        select, input, textarea {
            width: 100%;
            padding: 12px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #f8fafc;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }

        select {
            width: 130px;
            font-weight: bold;
            cursor: pointer;
        }

        select:focus, input:focus, textarea:focus {
            border-color: #38bdf8;
        }

        textarea {
            height: 140px;
            font-family: monospace;
            resize: vertical;
        }

        .btn {
            background: #0284c7;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
            transition: background 0.2s;
        }

        .btn:hover {
            background: #0369a1;
        }

        .response-container {
            margin-top: 24px;
            display: none;
        }

        .response-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
        }

        .status-success { background: #059669; color: #ecfdf5; }
        .status-error { background: #dc2626; color: #fef2f2; }

        pre {
            background: #0f172a;
            padding: 16px;
            border-radius: 6px;
            border: 1px solid #334155;
            overflow-x: auto;
            color: #a5f3fc;
            font-family: monospace;
            font-size: 0.9rem;
            max-height: 350px;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>🚀 API Endpoint Tester</h1>

    <form id="apiForm">
        <!-- Method & URL -->
        <div class="form-group">
            <label>Endpoint</label>
            <div class="input-group">
                <select id="method">
                    <option value="GET">GET</option>
                    <option value="POST" selected>POST</option>
                    <option value="PUT">PUT</option>
                    <option value="DELETE">DELETE</option>
                </select>
                <input type="text" id="url" placeholder="http://103.87.67.63/v1/login" required>
            </div>
        </div>

        <!-- Authorization (Bearer JWT) -->
        <div class="form-group">
            <label>Bearer Token (Authorization)</label>
            <input type="text" id="token" placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...">
        </div>

        <!-- Request Body -->
        <div class="form-group">
            <label>Request Body (JSON)</label>
            <textarea id="body" placeholder='{\n  "username": "rspad",\n  "password": "secretpassword"\n}'></textarea>
        </div>

        <button type="submit" class="btn" id="sendBtn">Send Request</button>
    </form>

    <!-- Response Box -->
    <div class="response-container" id="responseContainer">
        <div class="response-header">
            <label>Response Output</label>
            <span id="statusBadge" class="status-badge"></span>
        </div>
        <pre id="responseOutput"></pre>
    </div>
</div>

<script>
    document.getElementById('apiForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const method = document.getElementById('method').value;
        const url = document.getElementById('url').value;
        const token = document.getElementById('token').value.trim();
        const bodyInput = document.getElementById('body').value.trim();
        const btn = document.getElementById('sendBtn');

        const responseContainer = document.getElementById('responseContainer');
        const responseOutput = document.getElementById('responseOutput');
        const statusBadge = document.getElementById('statusBadge');

        btn.disabled = true;
        btn.innerText = 'Sending...';

        // Prepare Headers
        const headers = {
            'Content-Type': 'application/json'
        };

        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        // Prepare fetch options
        const options = {
            method: method,
            headers: headers
        };

        if (['POST', 'PUT', 'PATCH'].includes(method) && bodyInput) {
            try {
                // Ensure valid JSON format before sending
                options.body = JSON.stringify(JSON.parse(bodyInput));
            } catch (err) {
                alert('Invalid JSON structure in Request Body');
                btn.disabled = false;
                btn.innerText = 'Send Request';
                return;
            }
        }

        try {
            const startTime = performance.now();
            const res = await fetch(url, options);
            const duration = Math.round(performance.now() - startTime);

            statusBadge.innerText = `${res.status} ${res.statusText} (${duration}ms)`;
            statusBadge.className = `status-badge ${res.ok ? 'status-success' : 'status-error'}`;

            let data;
            const contentType = res.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                data = await res.json();
                responseOutput.textContent = JSON.stringify(data, null, 2);
            } else {
                data = await res.text();
                responseOutput.textContent = data;
            }

        } catch (error) {
            statusBadge.innerText = 'Network / CORS Error';
            statusBadge.className = 'status-badge status-error';
            responseOutput.textContent = `Error: ${error.message}\nCheck your server CORS settings or target URL.`;
        } finally {
            responseContainer.style.display = 'block';
            btn.disabled = false;
            btn.innerText = 'Send Request';
        }
    });
</script>

</body>
</html>
