<?php

namespace Tests\Feature\Database;

use Tests\TestCase;

class AntiPostgresLinterTest extends TestCase
{
    /**
     * Forbidden PostgreSQL-specific keywords/syntax that must NEVER appear in Usayd MySQL codebase.
     */
    private const FORBIDDEN_POSTGRES_PATTERNS = [
        '/\bILIKE\b/i' => 'PostgreSQL ILIKE operator is not supported by MySQL. Use LIKE or collation-based comparison.',
        '/::jsonb?\b/i' => 'PostgreSQL type casting (::json/::jsonb) is invalid in MySQL.',
        '/\bRETURNING\b/i' => 'PostgreSQL RETURNING clause in DML is not supported in MySQL.',
        '/\bgen_random_uuid\(\)/i' => 'PostgreSQL gen_random_uuid() is invalid in MySQL. Use UUID().',
        '/\bnow\(\)\s+at\s+time\s+zone\b/i' => 'PostgreSQL AT TIME ZONE syntax is invalid in MySQL. Use CONVERT_TZ().',
        '/\bserial\s+primary\s+key\b/i' => 'PostgreSQL SERIAL keyword is invalid in MySQL. Use AUTO_INCREMENT.',
        '/\bbytea\b/i' => 'PostgreSQL BYTEA data type is invalid in MySQL. Use BLOB.',
    ];

    public function test_no_postgres_specific_syntax_in_migrations(): void
    {
        $migrationsDir = database_path('migrations');
        $this->scanDirectoryForForbiddenPatterns($migrationsDir);
    }

    public function test_no_postgres_specific_syntax_in_app(): void
    {
        $appDir = app_path();
        $this->scanDirectoryForForbiddenPatterns($appDir);
    }

    public function test_no_postgres_specific_syntax_in_config(): void
    {
        $configDir = config_path();
        $this->scanDirectoryForForbiddenPatterns($configDir);
    }

    private function scanDirectoryForForbiddenPatterns(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN_POSTGRES_PATTERNS as $pattern => $message) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $content,
                    "File {$file->getPathname()} contains PostgreSQL-specific syntax: {$message}"
                );
            }
        }
    }
}
