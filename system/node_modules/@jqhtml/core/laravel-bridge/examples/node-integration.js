#!/usr/bin/env node

/**
 * Example Node.js integration for sending JQHTML errors to Laravel
 *
 * This shows how to compile JQHTML templates and send any errors
 * to a Laravel backend for proper error handling and display.
 */

import { Lexer, Parser, CodeGenerator, JQHTMLParseError } from '@jqhtml/parser';
import fs from 'fs';
import fetch from 'node-fetch'; // or axios, etc.

/**
 * Compile a JQHTML template and handle errors
 */
async function compileTemplate(templatePath, laravelEndpoint) {
  try {
    // Read template file
    const source = fs.readFileSync(templatePath, 'utf8');
    const filename = templatePath;

    // Compile with source maps
    const lexer = new Lexer(source);
    const tokens = lexer.tokenize();
    const parser = new Parser(tokens, source, filename);
    const ast = parser.parse();

    const generator = new CodeGenerator();
    const result = generator.generateWithSourceMap(ast, filename, source);

    // Success - return compiled code
    return {
      success: true,
      code: result.code,
      sourceMap: result.map
    };

  } catch (error) {
    // Format error for Laravel
    const errorData = formatErrorForLaravel(error, templatePath);

    // Send to Laravel if endpoint provided
    if (laravelEndpoint) {
      await sendErrorToLaravel(errorData, laravelEndpoint);
    }

    // Return error response
    return {
      success: false,
      error: errorData
    };
  }
}

/**
 * Format a JavaScript error for Laravel consumption
 */
function formatErrorForLaravel(error, templatePath) {
  // Check if it's a JQHTML parse error with full details
  if (error instanceof JQHTMLParseError || error.name === 'JQHTMLParseError') {
    return {
      message: error.message,
      filename: error.filename || templatePath,
      line: error.line,
      column: error.column,
      source: error.source,
      suggestion: error.suggestion,
      severity: error.severity || 'error',
      endLine: error.endLine,
      endColumn: error.endColumn
    };
  }

  // Generic error - try to extract what we can
  const errorData = {
    message: error.message || String(error),
    filename: templatePath,
    severity: 'error'
  };

  // Try to parse location from error message
  const locationMatch = error.message.match(/at line (\d+), column (\d+)/);
  if (locationMatch) {
    errorData.line = parseInt(locationMatch[1]);
    errorData.column = parseInt(locationMatch[2]);
  }

  return errorData;
}

/**
 * Send error data to Laravel backend
 */
async function sendErrorToLaravel(errorData, endpoint) {
  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        error: errorData,
        context: {
          node_version: process.version,
          timestamp: new Date().toISOString(),
          environment: process.env.NODE_ENV || 'development'
        }
      })
    });

    if (!response.ok) {
      console.error('Failed to send error to Laravel:', response.statusText);
    }

    return response.json();

  } catch (err) {
    console.error('Error communicating with Laravel:', err);
  }
}

/**
 * Express/HTTP endpoint for template compilation
 *
 * This could be used in a Node.js service that compiles templates
 * for a Laravel application.
 */
export function createCompilationEndpoint(app, laravelErrorEndpoint) {
  app.post('/compile-jqhtml', async (req, res) => {
    const { template, filename, source } = req.body;

    try {
      // Compile template
      const lexer = new Lexer(source || template);
      const tokens = lexer.tokenize();
      const parser = new Parser(tokens, source || template, filename);
      const ast = parser.parse();

      const generator = new CodeGenerator();
      const result = generator.generateWithSourceMap(
        ast,
        filename || 'template.jqhtml',
        source || template
      );

      // Success response
      res.json({
        success: true,
        compiled: {
          code: result.code,
          map: result.map
        }
      });

    } catch (error) {
      // Format error for Laravel
      const errorData = formatErrorForLaravel(error, filename);

      // Send error to Laravel for logging/display
      if (laravelErrorEndpoint) {
        sendErrorToLaravel(errorData, laravelErrorEndpoint);
      }

      // Return error response
      res.status(400).json({
        success: false,
        error: errorData
      });
    }
  });
}

/**
 * CLI usage example
 */
if (import.meta.url === `file://${process.argv[1]}`) {
  const templatePath = process.argv[2];
  const laravelEndpoint = process.argv[3] || process.env.LARAVEL_ERROR_ENDPOINT;

  if (!templatePath) {
    console.error('Usage: node-integration.js <template-file> [laravel-endpoint]');
    process.exit(1);
  }

  compileTemplate(templatePath, laravelEndpoint).then(result => {
    if (result.success) {
      console.log('Compilation successful!');
      console.log('Code length:', result.code.length);
      console.log('Source map:', result.sourceMap ? 'Generated' : 'Not generated');
    } else {
      console.error('Compilation failed!');
      console.error(result.error);
      process.exit(1);
    }
  });
}

// Export for use as module
export { compileTemplate, formatErrorForLaravel, sendErrorToLaravel };