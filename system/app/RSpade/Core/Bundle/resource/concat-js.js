#!/usr/bin/env node

/**
 * JavaScript concatenation script with source map support
 *
 * MIGRATED TO MOZILLA SOURCE-MAP LIBRARY (2025-09-23)
 * As per JQHTML team's RSPADE_SOURCEMAP_MIGRATION_GUIDE.md
 *
 * Usage: node concat-js.js <output-file> <input-file1> <input-file2> ...
 *
 * This script concatenates JavaScript files while preserving inline source maps
 * using Mozilla's source-map library for industry-standard compatibility.
 */

const fs = require('fs');
const path = require('path');
const { SourceMapConsumer, SourceMapGenerator, SourceNode } = require('source-map');

// Parse command line arguments
const args = process.argv.slice(2);
if (args.length < 2) {
    console.error('Usage: node concat-js.js <output-file> <input-file1> [input-file2] ...');
    process.exit(1);
}

const outputFile = args[0];
const inputFiles = args.slice(1);

/**
 * Extracts inline Base64 sourcemap from JavaScript content
 * CRITICAL: Uses exact regex pattern from JQHTML team - DO NOT MODIFY
 * Returns { content: string without sourcemap, map: parsed object or null }
 */
function extractSourceMap(content, filename) {
    // EXACT regex pattern - do not modify
    const regex = /(?:\/\/[@#][ \t]+sourceMappingURL=([^\s'"]+?)[ \t]*$)/m;
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
    // Create root SourceNode for concatenation
    const rootNode = new SourceNode(null, null, null);

    // Track source contents for embedding in sourcemap
    const sourceContents = {};

    // Process each input file
    for (const inputFile of inputFiles) {
        // Check if this is babel-transformed file with metadata
        // Format: babel_file_path::original_file_path
        let actualFile = inputFile;
        let displayPath = null;
        let isBabel = false;

        if (inputFile.includes('::')) {
            const parts = inputFile.split('::');
            actualFile = parts[0];  // The babel-transformed file to read
            displayPath = parts[1];  // The original source file for display
            isBabel = true;
        }

        if (!fs.existsSync(actualFile)) {
            console.error(`Error: Input file not found: ${actualFile}`);
            process.exit(1);
        }

        // Read file content
        const content = fs.readFileSync(actualFile, 'utf-8');

        // Generate relative path for better source map references
        const relativePath = displayPath
            ? path.relative(process.cwd(), displayPath)
            : path.relative(process.cwd(), actualFile);

        // Add file separator comment with (babel) suffix if transformed
        const banner = isBabel
            ? `/* === ${relativePath} (babel) === */\n`
            : `/* === ${relativePath} === */\n`;
        rootNode.add(banner);

        // Extract sourcemap if present
        const { content: cleanContent, map } = extractSourceMap(content, relativePath);

        // Store source content for embedding in sourcemap
        sourceContents[relativePath] = cleanContent;

        // Check if this is a compiled JQHTML file
        const isJqhtml = content.includes('/* Compiled from:') && content.includes('.jqhtml */');

        if (map) {
            // File has a sourcemap - use it
            let consumer = await new SourceMapConsumer(map);

            // Apply 2-line offset for JQHTML files
            if (isJqhtml) {
                // JQHTML templates need a 2-line offset because the template definition
                // starts on line 3 of the source (after <Define:ComponentName>)
                const offsetMap = JSON.parse(JSON.stringify(map));

                // Update mappings to add 2-line offset
                const generator = new SourceMapGenerator({
                    file: offsetMap.file,
                    sourceRoot: offsetMap.sourceRoot
                });

                // Re-apply all mappings with offset
                consumer.eachMapping(mapping => {
                    if (mapping.source && mapping.originalLine) {
                        generator.addMapping({
                            generated: {
                                line: mapping.generatedLine,
                                column: mapping.generatedColumn
                            },
                            original: {
                                line: mapping.originalLine + 2,  // Add 2-line offset
                                column: mapping.originalColumn
                            },
                            source: mapping.source,
                            name: mapping.name
                        });
                    }
                });

                // Clean up old consumer
                consumer.destroy();

                // Use new consumer with offset mappings
                const offsetMapJson = generator.toJSON();
                offsetMapJson.sources = map.sources;
                offsetMapJson.sourcesContent = map.sourcesContent;
                consumer = await new SourceMapConsumer(offsetMapJson);
            }

            // Store any additional sources from the existing sourcemap
            if (map.sourcesContent && map.sources) {
                map.sources.forEach((source, idx) => {
                    if (map.sourcesContent[idx]) {
                        sourceContents[source] = map.sourcesContent[idx];
                    }
                });
            }

            // Create a SourceNode from the file content with its sourcemap
            const node = SourceNode.fromStringWithSourceMap(
                cleanContent + '\n',  // Add newline separator between files
                consumer
            );

            rootNode.add(node);

            // Clean up consumer to prevent memory leaks
            consumer.destroy();
        } else {
            // No sourcemap - generate identity mappings (each line maps to itself)
            const lines = cleanContent.split('\n');
            const fileNode = new SourceNode();

            // Apply 2-line offset for JQHTML files without existing sourcemaps
            const lineOffset = isJqhtml ? 2 : 0;

            for (let i = 0; i < lines.length; i++) {
                // Map each line to its original position (with offset for JQHTML)
                fileNode.add(new SourceNode(
                    i + 1 + lineOffset,  // line (1-indexed) + offset for JQHTML
                    0,                   // column
                    relativePath,        // source filename
                    lines[i] + (i < lines.length - 1 ? '\n' : '')  // preserve newlines except last
                ));
            }

            // Add final newline separator
            fileNode.add('\n');
            rootNode.add(fileNode);
        }

        // Add extra newline between files
        rootNode.add('\n');
    }

    // Generate the concatenated result with merged sourcemap
    const { code, map } = rootNode.toStringWithSourceMap({
        file: path.basename(outputFile)
    });

    // Convert sourcemap to JSON for final processing
    const mapJSON = map.toJSON();

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
            // Try to find content for this source
            return sourceContents[source] || null;
        });
    }

    // Convert sourcemap to Base64 and append as inline comment
    // CRITICAL: Include charset=utf-8 as specified by JQHTML team
    const base64Map = Buffer.from(JSON.stringify(mapJSON)).toString('base64');
    const finalCode = code +
        `\n//# sourceMappingURL=data:application/json;charset=utf-8;base64,${base64Map}\n`;

    // Write the output file
    const outputDir = path.dirname(outputFile);
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }

    fs.writeFileSync(outputFile, finalCode, 'utf-8');

    // Report success
    const fileSizeKb = (fs.statSync(outputFile).size / 1024).toFixed(2);
    console.log(`✅ Concatenated ${inputFiles.length} files to ${outputFile} (${fileSizeKb} KB)`);

    // Report sourcemap details
    console.log(`   Sources in map: ${mapJSON.sources.join(', ')}`);
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