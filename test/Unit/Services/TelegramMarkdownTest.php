<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\TelegramMarkdown;
use PHPUnit\Framework\TestCase;

final class TelegramMarkdownTest extends TestCase
{
    private TelegramMarkdown $converter;

    protected function setUp(): void
    {
        $this->converter = new TelegramMarkdown();
    }

    public function testEmptyString(): void
    {
        $result = $this->converter->convertToMarkdownV2('');

        $this->assertSame('', $result);
    }

    public function testPlainText(): void
    {
        $result = $this->converter->convertToMarkdownV2('Hello World');

        $this->assertSame("Hello World\n", $result);
    }

    public function testBasicTextEscaping(): void
    {
        $markdown = 'Special chars: _ * [ ] ( ) ~ ` > # + - = | { } . !';
        $result = $this->converter->convertToMarkdownV2($markdown);

        $expected = "Special chars: \_ \* \[ \] \( \) \~ \` \> \# \+ \- \= \| \{ \} \. \!\n";
        $this->assertSame($expected, $result);
    }

    public function testBackslashEscaping(): void
    {
        $result = $this->converter->convertToMarkdownV2('Path: C:\\Users\\test');

        $this->assertSame("Path: C:\\\\Users\\\\test\n", $result);
    }

    public function testBoldText(): void
    {
        $result = $this->converter->convertToMarkdownV2('**bold text**');

        $this->assertSame("*bold text*\n", $result);
    }

    public function testBoldTextDoubleAsterisk(): void
    {
        $result = $this->converter->convertToMarkdownV2('**double** and **another**');

        $this->assertSame("*double* and *another*\n", $result);
    }

    public function testItalicTextWithAsterisk(): void
    {
        $result = $this->converter->convertToMarkdownV2('*italic text*');

        $this->assertSame("_italic text_\n", $result);
    }

    public function testItalicTextWithUnderscore(): void
    {
        $result = $this->converter->convertToMarkdownV2('_italic text_');

        $this->assertSame("_italic text_\n", $result);
    }

    public function testBoldAndItalicCombined(): void
    {
        $result = $this->converter->convertToMarkdownV2('***bold and italic***');

        $this->assertSame("_*bold and italic*_\n", $result);
    }

    public function testInlineCode(): void
    {
        $result = $this->converter->convertToMarkdownV2('`code`');

        $this->assertSame("`code`\n", $result);
    }

    public function testInlineCodeWithBacktick(): void
    {
        $result = $this->converter->convertToMarkdownV2('`code with ` backtick`');

        $this->assertSame("`code with ` backtick\`\n", $result);
    }

    public function testInlineCodeWithBackslash(): void
    {
        $result = $this->converter->convertToMarkdownV2('`C:\\path`');

        $this->assertSame("`C:\\\\path`\n", $result);
    }

    public function testCodeBlock(): void
    {
        $markdown = "```\ncode block\nline two\n```";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("```\ncode block\nline two\n\n```\n\n", $result);
    }

    public function testCodeBlockWithLanguage(): void
    {
        $markdown = "```php\n\$var = 1;\n```";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("```php\n\$var = 1;\n\n```\n\n", $result);
    }

    public function testCodeBlockWithBackticks(): void
    {
        $markdown = "```\n```nested```\n```";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("````\n```nested```\n\n````\n\n", $result);
    }

    public function testStrikethrough(): void
    {
        $result = $this->converter->convertToMarkdownV2('~~deleted text~~');

        $this->assertSame("~deleted text~\n", $result);
    }

    public function testSpoiler(): void
    {
        $result = $this->converter->convertToMarkdownV2('||spoiler text||');

        // Spoiler tags are stripped by HTML inline handler
        $this->assertSame("spoiler text\n", $result);
    }

    public function testMultipleSpoilers(): void
    {
        $result = $this->converter->convertToMarkdownV2('||first|| and ||second||');

        // Spoiler tags are stripped by HTML inline handler
        $this->assertSame("first and second\n", $result);
    }

    public function testLink(): void
    {
        $result = $this->converter->convertToMarkdownV2('[Google](https://google.com)');

        $this->assertSame("[Google](https://google.com)\n", $result);
    }

    public function testLinkWithSpecialCharsInUrl(): void
    {
        $result = $this->converter->convertToMarkdownV2('[Link](https://example.com/path)');

        $this->assertSame("[Link](https://example.com/path)\n", $result);
    }

    public function testLinkWithUrlAsText(): void
    {
        $result = $this->converter->convertToMarkdownV2('<https://google.com>');

        // The URL text gets double-escaped: once by escapeText and once more
        $this->assertSame("[https://google\\\\\\.com](https://google.com)\n", $result);
    }

    public function testHeadingH1(): void
    {
        $result = $this->converter->convertToMarkdownV2('# Heading 1');

        $this->assertSame("*Heading 1*\n\n", $result);
    }

    public function testHeadingH2(): void
    {
        $result = $this->converter->convertToMarkdownV2('## Heading 2');

        $this->assertSame("*Heading 2*\n\n", $result);
    }

    public function testHeadingH3(): void
    {
        $result = $this->converter->convertToMarkdownV2('### Heading 3');

        $this->assertSame("_Heading 3_\n\n", $result);
    }

    public function testHeadingH4(): void
    {
        $result = $this->converter->convertToMarkdownV2('#### Heading 4');

        $this->assertSame("__Heading 4__\n\n", $result);
    }

    public function testHeadingH5(): void
    {
        $result = $this->converter->convertToMarkdownV2('##### Heading 5');

        $this->assertSame("*_Heading 5_*\n\n", $result);
    }

    public function testHeadingH6(): void
    {
        $result = $this->converter->convertToMarkdownV2('###### Heading 6');

        $this->assertSame("Heading 6\n\n", $result);
    }

    public function testUnorderedList(): void
    {
        $markdown = "- Item 1\n- Item 2\n- Item 3";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("• Item 1\n• Item 2\n• Item 3\n\n", $result);
    }

    public function testUnorderedListWithAsterisk(): void
    {
        $markdown = "* Item 1\n* Item 2";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("• Item 1\n• Item 2\n\n", $result);
    }

    public function testOrderedList(): void
    {
        $markdown = "1. First\n2. Second\n3. Third";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("1\. First\n2\. Second\n3\. Third\n\n", $result);
    }

    public function testOrderedListStartingAtNumber(): void
    {
        $markdown = "5. Fifth\n6. Sixth";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("5\. Fifth\n6\. Sixth\n\n", $result);
    }

    public function testNestedList(): void
    {
        $markdown = "- Item 1\n  - Nested 1\n  - Nested 2\n- Item 2";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $expected = "• Item 1  • Nested 1\n  • Nested 2\n• Item 2\n\n";
        $this->assertSame($expected, $result);
    }

    public function testBlockquote(): void
    {
        $markdown = "> This is a quote";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("> This is a quote\n\n", $result);
    }

    public function testBlockquoteMultiline(): void
    {
        $markdown = "> Line 1\n> Line 2";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("> Line 1\n> Line 2\n\n", $result);
    }

    public function testThematicBreak(): void
    {
        $result = $this->converter->convertToMarkdownV2('---');

        $this->assertSame("\-\-\-\n", $result);
    }

    public function testTable(): void
    {
        $markdown = "| Col1 | Col2 |\n|------|------|\n| A    | B    |";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $expected = "```\n| Col1 | Col2 |\n| A | B |\n```\n\n";
        $this->assertSame($expected, $result);
    }

    public function testImageAsLink(): void
    {
        $result = $this->converter->convertToMarkdownV2('![Alt text](https://example.com/image.png)');

        $this->assertSame("[Alt text](https://example.com/image.png)\n", $result);
    }

    public function testImageWithoutUrl(): void
    {
        $result = $this->converter->convertToMarkdownV2('![Alt text]()');

        $this->assertSame("Alt text\n", $result);
    }

    public function testMixedContent(): void
    {
        $markdown = "# Title\n\nThis is **bold** and _italic_.\n\n`code` here.";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $expected = "*Title*\n\nThis is *bold* and _italic_\.\n`code` here\.\n";
        $this->assertSame($expected, $result);
    }

    public function testNestedFormatting(): void
    {
        $result = $this->converter->convertToMarkdownV2('**bold _and italic_**');

        $this->assertSame("*bold _and italic_*\n", $result);
    }

    public function testSpecialCharsInFormattedText(): void
    {
        $result = $this->converter->convertToMarkdownV2('**text_with_underscores**');

        $this->assertSame("*text\_with\_underscores*\n", $result);
    }

    public function testSpecialCharsInItalic(): void
    {
        $result = $this->converter->convertToMarkdownV2('_text [bracket]_');

        $this->assertSame("_text \[bracket\]_\n", $result);
    }

    public function testParagraphs(): void
    {
        $markdown = "Paragraph 1\n\nParagraph 2";
        $result = $this->converter->convertToMarkdownV2($markdown);

        // Paragraphs separated by single newline
        $this->assertSame("Paragraph 1\nParagraph 2\n", $result);
    }

    public function testLineBreaks(): void
    {
        $markdown = "Line 1  \nLine 2";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("Line 1\nLine 2\n", $result);
    }

    public function testHtmlStripping(): void
    {
        $result = $this->converter->convertToMarkdownV2('text <br> more');

        $this->assertSame("text  more\n", $result);
    }

    public function testMultipleParagraphsWithFormatting(): void
    {
        $markdown = "**Bold paragraph**\n\n_Italic paragraph_";
        $result = $this->converter->convertToMarkdownV2($markdown);

        // Paragraphs separated by single newline
        $this->assertSame("*Bold paragraph*\n_Italic paragraph_\n", $result);
    }

    public function testCodeInList(): void
    {
        $markdown = "- Item with `code`\n- Normal item";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("• Item with `code`\n• Normal item\n\n", $result);
    }

    public function testLinkInBold(): void
    {
        $result = $this->converter->convertToMarkdownV2('**[Link](https://example.com)**');

        $this->assertSame("*[Link](https://example.com)*\n", $result);
    }

    public function testEmailAddress(): void
    {
        $result = $this->converter->convertToMarkdownV2('test@example.com');

        // Dot is escaped in plain text
        $this->assertSame("test@example\.com\n", $result);
    }

    public function testEmoji(): void
    {
        $result = $this->converter->convertToMarkdownV2('Hello 👋 World 🌍');

        $this->assertSame("Hello 👋 World 🌍\n", $result);
    }

    public function testUnicodeCharacters(): void
    {
        $result = $this->converter->convertToMarkdownV2('Café résumé naïve');

        $this->assertSame("Café résumé naïve\n", $result);
    }

    public function testNumbersAndSymbols(): void
    {
        $result = $this->converter->convertToMarkdownV2('Price: $100.50 (20% off)');

        // Dots and parentheses are escaped, percent is not
        $this->assertSame("Price: \$100\.50 \(20% off\)\n", $result);
    }

    public function testMultipleCodeBlocks(): void
    {
        $markdown = "```php\n\$a = 1;\n```\n\n```js\nlet b = 2;\n```";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $expected = "```php\n\$a = 1;\n\n```\n\n```js\nlet b = 2;\n\n```\n\n";
        $this->assertSame($expected, $result);
    }

    public function testBlockquoteWithFormatting(): void
    {
        $markdown = "> **Bold quote**";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("> *Bold quote*\n\n", $result);
    }

    public function testEmptyFormattedText(): void
    {
        $result = $this->converter->convertToMarkdownV2('****');

        // Four asterisks is parsed as thematic break
        $this->assertSame("\-\-\-\n", $result);
    }

    public function testWhitespaceInFormattedText(): void
    {
        $result = $this->converter->convertToMarkdownV2('**  text  **');

        // Text surrounded by spaces before markers - not parsed as bold
        $this->assertSame("\*\*  text  \*\*\n", $result);
    }

    public function testIndentedCodeBlock(): void
    {
        $markdown = "    code line 1\n    code line 2";
        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertSame("```\ncode line 1\ncode line 2\n\n```\n\n", $result);
    }

    public function testComplexNestedStructure(): void
    {
        $markdown = "# Main Title\n\n## Section\n\nParagraph with **bold**, _italic_, and `code`.\n\n> A quote with **bold**\n\n- List item 1\n- List item 2 with `code`\n  - Nested item\n\n```python\ndef hello():\n    pass\n```\n\n[Visit us](https://example.com)";

        $result = $this->converter->convertToMarkdownV2($markdown);

        $this->assertStringContainsString('*Main Title*', $result);
        $this->assertStringContainsString('*Section*', $result);
        $this->assertStringContainsString('*bold*', $result);
        $this->assertStringContainsString('_italic_', $result);
        $this->assertStringContainsString('`code`', $result);
        $this->assertStringContainsString('> A quote', $result);
        $this->assertStringContainsString('• List item', $result);
        $this->assertStringContainsString('```python', $result);
        $this->assertStringContainsString('[Visit us]', $result);
    }

    public function testDotInPlainText(): void
    {
        $result = $this->converter->convertToMarkdownV2('Hello world. End.');

        $this->assertSame("Hello world\. End\.\n", $result);
    }

    public function testDotInBoldText(): void
    {
        $result = $this->converter->convertToMarkdownV2('**Hello world. End.**');

        $this->assertSame("*Hello world\. End\.*\n", $result);
    }

    public function testDotInItalicText(): void
    {
        $result = $this->converter->convertToMarkdownV2('_Hello world. End._');

        $this->assertSame("_Hello world\. End\._\n", $result);
    }

    public function testDotInLink(): void
    {
        $result = $this->converter->convertToMarkdownV2('[Click here. Now.](https://example.com)');

        $this->assertSame("[Click here\\\\\\. Now\\\\\\.](https://example.com)\n", $result);
    }

    public function testMultiplePunctuation(): void
    {
        $result = $this->converter->convertToMarkdownV2('Wow! Amazing. Really!');

        $this->assertSame("Wow\! Amazing\. Really\!\n", $result);
    }

    public function testPreEscapedCharacters(): void
    {
        // LLM outputs often contain pre-escaped chars - should not double escape
        $markdown = 'Special chars: \_ \* \- \[ \] \( \) \~ \` \> \# \+ \= \| \{ \} \. \!';
        $result = $this->converter->convertToMarkdownV2($markdown);

        $expected = "Special chars: \_ \* \- \[ \] \( \) \~ \` \> \# \+ \= \| \{ \} \. \!\n";
        $this->assertSame($expected, $result);
    }

    public function testPreEscapedBackslash(): void
    {
        $result = $this->converter->convertToMarkdownV2('Path: C:\\\\Users\\\\test');

        $this->assertSame("Path: C:\\\\Users\\\\test\n", $result);
    }

    public function testMixedEscapedAndUnescaped(): void
    {
        $markdown = "Already escaped: \_ and not escaped: _";
        $result = $this->converter->convertToMarkdownV2($markdown);

        // Both should result in escaped underscores in the output
        $this->assertStringContainsString('\_', $result);
    }
}
