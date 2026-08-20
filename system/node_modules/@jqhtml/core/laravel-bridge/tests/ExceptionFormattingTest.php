<?php

namespace Jqhtml\LaravelBridge\Tests;

use PHPUnit\Framework\TestCase;
use Jqhtml\LaravelBridge\JqhtmlException;
use Jqhtml\LaravelBridge\JqhtmlErrorFormatter;

class ExceptionFormattingTest extends TestCase
{
    protected $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new JqhtmlErrorFormatter();
    }

    public function testBasicExceptionCreation()
    {
        $exception = new JqhtmlException(
            'Unclosed component definition',
            'templates/test.jqhtml',
            10,
            15
        );

        $this->assertEquals('Unclosed component definition', $exception->getMessage());
        $this->assertEquals('templates/test.jqhtml', $exception->getTemplateFile());
        $this->assertEquals(10, $exception->getTemplateLine());
        $this->assertEquals(15, $exception->getTemplateColumn());
    }

    public function testExceptionWithSuggestion()
    {
        $exception = new JqhtmlException(
            'Unclosed component definition',
            'templates/test.jqhtml',
            10,
            15,
            null,
            'Did you forget </Define:ComponentName>?'
        );

        $this->assertEquals('Did you forget </Define:ComponentName>?', $exception->getSuggestion());

        $formatted = $exception->getFormattedMessage();
        $this->assertStringContainsString('Did you forget', $formatted);
    }

    public function testExceptionFromJsError()
    {
        $jsError = [
            'message' => 'Syntax error: unexpected token',
            'filename' => 'app.jqhtml',
            'line' => 42,
            'column' => 8,
            'suggestion' => 'Check for missing closing tags',
            'severity' => 'error'
        ];

        $exception = JqhtmlException::createFromJsError($jsError);

        $this->assertEquals('Syntax error: unexpected token', $exception->getMessage());
        $this->assertEquals('app.jqhtml', $exception->getTemplateFile());
        $this->assertEquals(42, $exception->getTemplateLine());
        $this->assertEquals(8, $exception->getTemplateColumn());
        $this->assertEquals('Check for missing closing tags', $exception->getSuggestion());
    }

    public function testExceptionFromJsonString()
    {
        $json = json_encode([
            'message' => 'Parse error',
            'templateFile' => 'template.jqhtml',
            'line' => 5,
            'column' => 10
        ]);

        $exception = JqhtmlException::createFromJsError($json);

        $this->assertEquals('Parse error', $exception->getMessage());
        $this->assertEquals('template.jqhtml', $exception->getTemplateFile());
        $this->assertEquals(5, $exception->getTemplateLine());
        $this->assertEquals(10, $exception->getTemplateColumn());
    }

    public function testFormatterBasicFormat()
    {
        $exception = new JqhtmlException(
            'Test error',
            'test.jqhtml',
            20,
            5
        );

        $formatted = $this->formatter->format($exception);

        $this->assertArrayHasKey('message', $formatted);
        $this->assertArrayHasKey('file', $formatted);
        $this->assertArrayHasKey('line', $formatted);
        $this->assertArrayHasKey('column', $formatted);
        $this->assertArrayHasKey('error_type', $formatted);

        $this->assertEquals('Test error', $formatted['message']);
        $this->assertEquals('test.jqhtml', $formatted['file']);
        $this->assertEquals(20, $formatted['line']);
        $this->assertEquals(5, $formatted['column']);
    }

    public function testFormatterWithSourceContext()
    {
        $sourceCode = "line 1\nline 2\nline 3 with error\nline 4\nline 5";

        $exception = new JqhtmlException(
            'Error on line 3',
            'test.jqhtml',
            3,
            10,
            $sourceCode
        );

        $formatter = new JqhtmlErrorFormatter(null, true);
        $formatted = $formatter->format($exception);

        $this->assertArrayHasKey('source_context', $formatted);

        $context = $formatted['source_context'];
        $this->assertIsArray($context);

        // Find the error line in context
        $errorLine = null;
        foreach ($context as $line) {
            if ($line['is_error_line']) {
                $errorLine = $line;
                break;
            }
        }

        $this->assertNotNull($errorLine);
        $this->assertEquals(3, $errorLine['line_number']);
        $this->assertEquals('line 3 with error', $errorLine['content']);
        $this->assertEquals(10, $errorLine['error_column']);
    }

    public function testFormatterJsonFormat()
    {
        $exception = new JqhtmlException('JSON test error');

        $json = $this->formatter->formatForJson($exception);

        $this->assertArrayHasKey('error', $json);
        $this->assertArrayHasKey('type', $json);
        $this->assertArrayHasKey('message', $json);
        $this->assertArrayHasKey('details', $json);

        $this->assertTrue($json['error']);
        $this->assertEquals('jqhtml_error', $json['type']);
        $this->assertEquals('JSON test error', $json['message']);
    }

    public function testWrapGenericException()
    {
        $genericException = new \Exception(
            'JQHTMLParseError: Unclosed tag at line 10, column 5'
        );

        $wrapped = $this->formatter->wrapException($genericException);

        $this->assertInstanceOf(JqhtmlException::class, $wrapped);
        $this->assertEquals(10, $wrapped->getTemplateLine());
        $this->assertEquals(5, $wrapped->getTemplateColumn());
    }

    public function testWrapExceptionWithFilename()
    {
        $genericException = new \Exception(
            'Error at component.jqhtml:15:20 - syntax error'
        );

        $wrapped = $this->formatter->wrapException($genericException);

        $this->assertInstanceOf(JqhtmlException::class, $wrapped);
        $this->assertEquals('component.jqhtml', $wrapped->getTemplateFile());
        $this->assertEquals(15, $wrapped->getTemplateLine());
        $this->assertEquals(20, $wrapped->getTemplateColumn());
    }

    public function testCodeSnippetGeneration()
    {
        $source = implode("\n", [
            'line 1',
            'line 2',
            'line 3',
            'error is here', // line 4
            'line 5',
            'line 6',
            'line 7'
        ]);

        $exception = new JqhtmlException(
            'Error message',
            'test.jqhtml',
            4,
            8,
            $source
        );

        $formatted = $exception->getFormattedMessage();

        // Should show context lines
        $this->assertStringContainsString('line 3', $formatted);
        $this->assertStringContainsString('error is here', $formatted);
        $this->assertStringContainsString('line 5', $formatted);

        // Should have error pointer
        $this->assertStringContainsString('^', $formatted);
    }
}