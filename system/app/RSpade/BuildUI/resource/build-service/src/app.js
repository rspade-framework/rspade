/**
 * RSpade Build UI - Terminal Application
 */

import { Terminal } from 'xterm';
import { FitAddon } from 'xterm-addon-fit';

// Use nginx-proxied WebSocket path
const WS_URL = `${window.location.protocol === 'https:' ? 'wss:' : 'ws:'}//${window.location.host}/_build/ws`;

class BuildTerminal {
    constructor() {
        this.ws = null;
        this.connected = false;
        this.term = null;
        this.fitAddon = null;
    }

    initialize() {
        // Create terminal instance
        this.term = new Terminal({
            theme: {
                background: '#1e1e1e',
                foreground: '#d4d4d4',
                cursor: '#4ec9b0',
                cursorAccent: '#1e1e1e',
                black: '#000000',
                red: '#cd3131',
                green: '#0dbc79',
                yellow: '#e5e510',
                blue: '#2472c8',
                magenta: '#bc3fbc',
                cyan: '#11a8cd',
                white: '#e5e5e5',
                brightBlack: '#666666',
                brightRed: '#f14c4c',
                brightGreen: '#23d18b',
                brightYellow: '#f5f543',
                brightBlue: '#3b8eea',
                brightMagenta: '#d670d6',
                brightCyan: '#29b8db',
                brightWhite: '#e5e5e5'
            },
            fontSize: 14,
            fontFamily: 'Menlo, Monaco, "Courier New", monospace',
            cursorBlink: true,
            cursorStyle: 'block',
            scrollback: 10000,
            convertEol: true
        });

        // Create fit addon for responsive sizing
        this.fitAddon = new FitAddon();
        this.term.loadAddon(this.fitAddon);

        // Open terminal in the container
        const container = document.getElementById('terminal');
        this.term.open(container);

        // Fit to container size
        this.fitAddon.fit();

        // Handle window resize
        window.addEventListener('resize', () => {
            this.fitAddon.fit();
        });

        // Write welcome message
        this.term.writeln('\x1b[1;32m╔════════════════════════════════════════════════════════════════╗\x1b[0m');
        this.term.writeln('\x1b[1;32m║\x1b[0m  \x1b[1;36mRSpade Asset Compiler\x1b[0m                                      \x1b[1;32m║\x1b[0m');
        this.term.writeln('\x1b[1;32m╚════════════════════════════════════════════════════════════════╝\x1b[0m');
        this.term.writeln('');

        // Connect to WebSocket
        this.connectWebSocket();
    }

    connectWebSocket() {
        this.term.writeln('\x1b[36m[INFO]\x1b[0m Connecting to WebSocket...');

        try {
            this.ws = new WebSocket(WS_URL);

            this.ws.onopen = () => {
                console.log('[BuildUI] WebSocket connected');
                this.connected = true;
                this.term.writeln('\x1b[32m[OK]\x1b[0m WebSocket connected');
                this.term.writeln('');
            };

            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                console.log('[BuildUI] Received:', data);

                // Display message in terminal
                if (data.type === 'connected') {
                    this.term.writeln(`\x1b[36m[SERVER]\x1b[0m ${data.message}`);
                    this.term.writeln('');
                } else if (data.type === 'output') {
                    this.term.write(data.content);
                } else if (data.type === 'build_complete') {
                    this.term.writeln('');
                    this.term.writeln('\x1b[90m' + '─'.repeat(64) + '\x1b[0m');
                    if (data.exit_code === 0) {
                        this.term.writeln('\x1b[1;32m[BUILD COMPLETE]\x1b[0m Build finished successfully!');
                    } else {
                        this.term.writeln(`\x1b[1;31m[BUILD FAILED]\x1b[0m Build exited with code ${data.exit_code}`);
                    }
                    this.term.writeln('\x1b[90m' + '─'.repeat(64) + '\x1b[0m');
                    this.term.writeln('');
                } else if (data.type === 'build_error') {
                    this.term.writeln('');
                    this.term.writeln(`\x1b[31m[ERROR]\x1b[0m Build error: ${data.error}`);
                    this.term.writeln('');
                } else {
                    this.term.writeln(`\x1b[90m[${data.type}]\x1b[0m ${JSON.stringify(data)}`);
                }
            };

            this.ws.onerror = (error) => {
                console.error('[BuildUI] WebSocket error:', error);
                this.term.writeln('\x1b[31m[ERROR]\x1b[0m WebSocket connection error');
            };

            this.ws.onclose = () => {
                console.log('[BuildUI] WebSocket disconnected');
                this.connected = false;
                this.term.writeln('\x1b[33m[WARN]\x1b[0m WebSocket disconnected');
                this.term.writeln('\x1b[36m[INFO]\x1b[0m Reconnecting in 2 seconds...');

                // Attempt reconnect after 2 seconds
                setTimeout(() => this.connectWebSocket(), 2000);
            };
        } catch (error) {
            console.error('[BuildUI] Connection error:', error);
            this.term.writeln('\x1b[31m[ERROR]\x1b[0m Connection failed');
        }
    }

    sendMessage(type, payload) {
        if (this.connected) {
            this.ws.send(JSON.stringify({ type, ...payload }));
        } else {
            console.error('[BuildUI] Cannot send message - not connected');
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    console.log('[BuildUI] Initializing terminal...');

    const terminal = new BuildTerminal();
    terminal.initialize();

    // Make terminal available globally for testing
    window.buildTerminal = terminal;
});
