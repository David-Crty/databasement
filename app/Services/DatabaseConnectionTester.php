<?php

namespace App\Services;

use App\Enums\DatabaseType;
use Exception;
use PDO;
use PDOException;

class DatabaseConnectionTester
{
    /**
     * Test a database connection with the provided credentials.
     *
     * @param  array{database_type: string, host: string, port: int, username: string, password: string, database_name: ?string}  $config
     * @return array{success: bool, message: string}
     */
    public function test(array $config): array
    {
        try {
            $databaseType = DatabaseType::tryFrom($config['database_type']);

            if ($databaseType === null) {
                return [
                    'success' => false,
                    'message' => "Unsupported database type: {$config['database_type']}",
                ];
            }

            $dsn = $databaseType->buildDsn(
                $config['host'],
                $config['port'],
                $config['database_name'] ?? null
            );

            $pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5, // 5 second timeout
                ]
            );

            // Test the connection by running a simple query
            $pdo->query('SELECT 1');

            return [
                'success' => true,
                'message' => 'Successfully connected to the database server!',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => $this->formatErrorMessage($e),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to connect: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Format PDO exception message for user-friendly display.
     */
    private function formatErrorMessage(PDOException $e): string
    {
        $message = $e->getMessage();

        // Common error patterns
        if (str_contains($message, 'Access denied')) {
            return 'Access denied. Please check your username and password.';
        }

        if (str_contains($message, 'Unknown database')) {
            return 'Database not found. Please check the database name.';
        }

        if (str_contains($message, 'Connection refused') || str_contains($message, 'Connection timed out')) {
            return 'Connection refused. Please check the host and port.';
        }

        if (str_contains($message, "Can't connect")) {
            return 'Unable to connect to the database server. Please verify the host and port.';
        }

        // Return sanitized error message
        return 'Connection failed: '.$message;
    }
}
