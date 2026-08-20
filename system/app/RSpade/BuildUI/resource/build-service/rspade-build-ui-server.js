const express = require('express');
const { WebSocketServer } = require('ws');
const http = require('http');
const path = require('path');
const { spawn } = require('child_process');

const app = express();
const PORT = 3100;
const WS_PORT = 3101;

// Build process state
let buildProcess = null;
let buildBuffer = [];
let isBuildRunning = false;

// Serve static files from public directory
app.use(express.static(path.join(__dirname, 'public')));

// Serve node_modules for xterm.css and other assets
app.use('/node_modules', express.static(path.join(__dirname, 'node_modules')));

// Hello world demo page
app.get('/_hello_world', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'hello_world.html'));
});

// Hello world endpoint
app.get('/api/hello', (req, res) => {
    res.json({ message: 'Hello from RSpade Build UI Service!' });
});

// Health check endpoint
app.get('/api/health', (req, res) => {
    res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

// Start HTTP server
const server = http.createServer(app);
server.listen(PORT, () => {
    console.log(`[BuildUI] HTTP server running on http://localhost:${PORT}`);
});

// Create WebSocket server
const wss = new WebSocketServer({ port: WS_PORT });

// Track connected clients
let clients = new Set();

// Broadcast message to all connected clients
function broadcast(message) {
    const json = JSON.stringify(message);
    clients.forEach(client => {
        if (client.readyState === 1) { // OPEN
            client.send(json);
        }
    });
}

// Start build process
function startBuild() {
    if (isBuildRunning) {
        console.log('[BuildUI] Build already running, skipping');
        return;
    }

    console.log('[BuildUI] Starting build process...');
    isBuildRunning = true;
    buildBuffer = [];

    // Spawn php artisan command with --build-debug flag
    buildProcess = spawn('php', ['artisan', 'rsx:bundle:compile', '--build-debug'], {
        cwd: '/var/www/html',
        env: process.env
    });

    // Capture stdout
    buildProcess.stdout.on('data', (data) => {
        const content = data.toString();
        buildBuffer.push(content);
        broadcast({
            type: 'output',
            content: content
        });
    });

    // Capture stderr
    buildProcess.stderr.on('data', (data) => {
        const content = data.toString();
        buildBuffer.push(content);
        broadcast({
            type: 'output',
            content: content
        });
    });

    // Handle completion
    buildProcess.on('close', (code) => {
        console.log(`[BuildUI] Build process exited with code ${code}`);
        isBuildRunning = false;
        buildProcess = null;

        broadcast({
            type: 'build_complete',
            exit_code: code,
            timestamp: new Date().toISOString()
        });
    });

    // Handle errors
    buildProcess.on('error', (error) => {
        console.error('[BuildUI] Build process error:', error);
        isBuildRunning = false;
        buildProcess = null;

        broadcast({
            type: 'build_error',
            error: error.message,
            timestamp: new Date().toISOString()
        });
    });
}

wss.on('connection', (ws) => {
    console.log('[BuildUI] Client connected to WebSocket');
    clients.add(ws);

    // Send welcome message
    ws.send(JSON.stringify({
        type: 'connected',
        message: 'Connected to RSpade Build UI WebSocket',
        timestamp: new Date().toISOString()
    }));

    // If build is running, send buffered output to new client
    if (isBuildRunning && buildBuffer.length > 0) {
        console.log(`[BuildUI] Sending ${buildBuffer.length} buffered chunks to new client`);
        buildBuffer.forEach(content => {
            ws.send(JSON.stringify({
                type: 'output',
                content: content
            }));
        });
    }

    // Start build if not already running
    if (!isBuildRunning) {
        startBuild();
    }

    // Handle incoming messages
    ws.on('message', (data) => {
        try {
            const message = JSON.parse(data.toString());
            console.log('[BuildUI] Received message:', message);
        } catch (error) {
            console.error('[BuildUI] Error parsing message:', error);
        }
    });

    // Handle disconnect
    ws.on('close', () => {
        console.log('[BuildUI] Client disconnected from WebSocket');
        clients.delete(ws);
    });

    // Handle errors
    ws.on('error', (error) => {
        console.error('[BuildUI] WebSocket error:', error);
        clients.delete(ws);
    });
});

console.log(`[BuildUI] WebSocket server running on ws://localhost:${WS_PORT}`);

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[BuildUI] SIGTERM received, shutting down gracefully');
    server.close(() => {
        console.log('[BuildUI] HTTP server closed');
        wss.close(() => {
            console.log('[BuildUI] WebSocket server closed');
            process.exit(0);
        });
    });
});
