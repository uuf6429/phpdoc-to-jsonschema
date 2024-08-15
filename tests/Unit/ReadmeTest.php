<?php

namespace Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
class ReadmeTest extends TestCase
{
    private const README_FILE = __DIR__ . '/../../README.md';

    #[DataProvider('readmeDataProvider')]
    public function testThatExampleWorks(string $inputCode, string $expectedOutput): void
    {
        $this->expectOutputString($expectedOutput);

        if (str_starts_with($inputCode, '<?php')) {
            $inputCode = substr($inputCode, 5);
        }

        eval($inputCode);
    }

    /**
     * @return iterable<array{inputCode: string, expectedOutput: string}>
     */
    public static function readmeDataProvider(): iterable
    {
        $readme = file_get_contents(str_replace('/', DIRECTORY_SEPARATOR, self::README_FILE));
        if ($readme === false) {
            self::fail('The README.md file could not be read: ' . str_replace('/', DIRECTORY_SEPARATOR, self::README_FILE));
        }

        if (!preg_match('/\R## 🚀 Usage\R(.*)\R## 📖 Documentation\R/su', $readme, $matches)) {
            self::fail('"Usage" section could not be parsed from README.md file');
        }

        if (!preg_match_all('/\R```(?<type>\w+)\R(?<text>.+?)\R```\R/s', $matches[1], $matches)) {
            self::fail('Examples could not be parsed from "Usage" section in README.md file');
        }

        foreach (array_chunk($matches['text'], 2) as $i => $chunk) {
            yield "Example $i" => ['inputCode' => $chunk[0], 'expectedOutput' => $chunk[1]];
        }
    }
}
