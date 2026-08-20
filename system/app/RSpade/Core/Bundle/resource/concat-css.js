#!/usr/bin/env node

/**
 * CSS concatenation script with source map support
 *
 * Based on concat-js.js architecture
 * Uses Mozilla source-map library for standard compatibility
 *
 * Usage: node concat-css.js <output-file> <input-file1> <input-file2> ...
 *
 * This script concatenates CSS files while preserving inline source maps.
 */

const fs = require('fs');
const path = require('path');
const { SourceMapConsumer, SourceMapGenerator } = require('source-map');

// Parse command line arguments
const args = process.argv.slice(2);
if (args.length < 2) {
    console.error('Usage: node concat-css.js <output-file> <input-file1> [input-file2] ...');
    process.exit(1);
}

const outputFile = args[0];
const inputFiles = args.slice(1);

/**
 * Extracts inline Base64 sourcemap from CSS content
 * Returns { content: string without sourcemap, map: parsed object or null }
 */
function extractSourceMap(content, filename) {
    // CSS sourcemap comment format
    const regex = /\/\*#\s*sourceMappingURL=([^\s*]+)\s*\*\//m;
    const match = content.match(regex);

    if (!match || !match[1]) {
        return { content, map: null };
    }

    const url = match[1];

    // Handle inline Base64 data URLs
    if (url.startsWith('data:')) {
        const base64Match = url.match(/base64,(.*)$/);
        if (base64Match) {
            try {
                const json = Buffer.from(base64Match[1], 'base64').toString('utf8');
                const map = JSON.parse(json);

                // Remove sourcemap comment from content
                const cleanContent = content.replace(regex, '');

                return { content: cleanContent, map };
            } catch (e) {
                console.warn(`Warning: Failed to parse sourcemap for ${filename}: ${e.message}`);
                return { content, map: null };
            }
        }
    }

    // External sourcemap files not supported in concatenation
    console.warn(`Warning: External sourcemap "${url}" in ${filename} will be ignored`);
    return { content, map: null };
}

/**
 * Main concatenation logic using Mozilla source-map library
 */
async function concatenateFiles() {
    // Create combined sourcemap generator
    const generator = new SourceMapGenerator({
        file: path.basename(outputFile)
    });

    // Track source contents for embedding
    const sourceContents = {};

    // Output content parts
    const outputParts = [];

    // Add header comment
    outputParts.push(`/* Concatenated CSS bundle: ${path.basename(outputFile)} */\n`);
    outputParts.push(`/* Generated: ${new Date().toISOString()} */\n\n`);

    let currentLine = 3; // Start after header comments

    // Process each input file
    for (const inputFile of inputFiles) {
        if (!fs.existsSync(inputFile)) {
            console.error(`Error: Input file not found: ${inputFile}`);
            process.exit(1);
        }

        // Read file content
        const content = fs.readFileSync(inputFile, 'utf-8');

        // Generate relative path for better source map references
        const relativePath = path.relative(process.cwd(), inputFile);

        // Add file separator comment
        const separatorComment = `/* === ${relativePath} === */\n`;
        outputParts.push(separatorComment);
        currentLine++;

        // Extract sourcemap if present
        const { content: cleanContent, map } = extractSourceMap(content, relativePath);

        // Store source content for embedding
        sourceContents[relativePath] = cleanContent;

        if (map) {
            // File has a sourcemap - merge it
            const consumer = await new SourceMapConsumer(map);

            // Store source contents from the existing sourcemap
            if (map.sourcesContent && map.sources) {
                map.sources.forEach((source, idx) => {
                    if (map.sourcesContent[idx]) {
                        sourceContents[source] = map.sourcesContent[idx];
                    }
                });
            }

            // Map each line through the existing sourcemap
            const lines = cleanContent.split('\n');
            for (let i = 0; i < lines.length; i++) {
                const line = lines[i];

                // For each line, try to map it back to original source
                const originalPos = consumer.originalPositionFor({
                    line: i + 1,
                    column: 0
                });

                if (originalPos.source) {
                    // We found an original mapping
                    generator.addMapping({
                        generated: {
                            line: currentLine,
                            column: 0
                        },
                        original: {
                            line: originalPos.line,
                            column: originalPos.column || 0
                        },
                        source: originalPos.source
                    });
                }

                outputParts.push(line + (i < lines.length - 1 ? '\n' : ''));
                currentLine++;
            }

            consumer.destroy();
        } else {
            // No sourcemap - generate identity mappings
            const lines = cleanContent.split('\n');
            for (let i = 0; i < lines.length; i++) {
                // Map each line to itself in the original file
                generator.addMapping({
                    generated: {
                        line: currentLine,
                        column: 0
                    },
                    original: {
                        line: i + 1,
                        column: 0
                    },
                    source: relativePath
                });

                outputParts.push(lines[i] + (i < lines.length - 1 ? '\n' : ''));
                currentLine++;
            }
        }

        // Add extra newline between files
        outputParts.push('\n');
        currentLine++;
    }

    // Generate the final sourcemap
    const mapJSON = generator.toJSON();

    // Ensure sourceRoot is set properly
    mapJSON.sourceRoot = '';

    // Ensure all sources are relative paths
    if (mapJSON.sources) {
        mapJSON.sources = mapJSON.sources.map(source => {
            if (!source) return source;
            // If it's an absolute path, make it relative
            if (path.isAbsolute(source)) {
                return path.relative(process.cwd(), source);
            }
            return source;
        });
    }

    // Add source contents to the sourcemap for inline viewing
    if (mapJSON.sources) {
        mapJSON.sourcesContent = mapJSON.sources.map(source => {
            return sourceContents[source] || null;
        });
    }

    // Convert sourcemap to Base64 and append as inline comment
    const base64Map = Buffer.from(JSON.stringify(mapJSON)).toString('base64');
    const finalContent = outputParts.join('') +
        `\n/*# sourceMappingURL=data:application/json;charset=utf-8;base64,${base64Map} */\n`;

    // Write the output file
    const outputDir = path.dirname(outputFile);
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }

    fs.writeFileSync(outputFile, finalContent, 'utf-8');

    // Report success
    const fileSizeKb = (fs.statSync(outputFile).size / 1024).toFixed(2);
    console.log(`✅ Concatenated ${inputFiles.length} CSS files to ${outputFile} (${fileSizeKb} KB)`);

    // Report sourcemap details
    console.log(`   Sources in map: ${mapJSON.sources.length} files`);
    const mapSizeKb = (Buffer.byteLength(base64Map, 'utf-8') / 1024).toFixed(2);
    console.log(`   Inline sourcemap: ${mapSizeKb} KB`);
}

// Run the concatenation
concatenateFiles().catch(err => {
    console.error(`Error: ${err.message}`);
    if (err.stack) {
        console.error(err.stack);
    }
    process.exit(1);
});
