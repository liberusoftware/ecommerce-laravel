<?php

namespace App\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Executor\Executor;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

/**
 * A wall-clock bound on one GraphQL request.
 *
 * Depth and complexity bound the *shape* of a query and the row caps bound how
 * much each list returns, but none of them bounds **time**. A query can sit
 * inside every one of those limits and still occupy a worker for as long as the
 * database takes — and `throttle:api` counts requests, not the cost of one. On a
 * public endpoint that is the difference between a slow page and an endpoint
 * that stops answering.
 *
 * The deadline is checked before each resolver runs. That is the honest
 * granularity: it stops a query that is *still going*, and it cannot interrupt
 * a single slow statement already in flight. `ponytail: per-resolver check —
 * a statement-level timeout belongs on the database connection if one query
 * can outlive the deadline on its own.`
 *
 * Exceeding it produces a GraphQL error rather than an exception, so the caller
 * gets the partial result and a reason instead of a 500 with nothing in it.
 */
class ExecutionDeadline
{
    private readonly float $expiresAt;

    public function __construct(private readonly float $seconds)
    {
        // Monotonic: `microtime` follows the system clock, and a clock stepped
        // backwards mid-request would extend the deadline rather than end it.
        $this->expiresAt = hrtime(true) / 1e9 + $seconds;
    }

    /**
     * Wrap every resolver in the schema, and return the default resolver to
     * hand to `executeQuery()` for the fields that have none.
     *
     * Centrally rather than at each resolver: this schema has thirty-odd of
     * them and the one somebody forgets is the one that runs long.
     */
    public function guard(Schema $schema): callable
    {
        foreach ($schema->getTypeMap() as $type) {
            if (! $type instanceof ObjectType) {
                continue;
            }

            foreach ($type->getFields() as $field) {
                if ($field->resolveFn === null) {
                    continue;
                }

                $resolve = $field->resolveFn;

                $field->resolveFn = function (...$arguments) use ($resolve) {
                    $this->check();

                    return $resolve(...$arguments);
                };
            }
        }

        return function (...$arguments) {
            $this->check();

            return Executor::defaultFieldResolver(...$arguments);
        };
    }

    /**
     * @throws Error
     */
    private function check(): void
    {
        if (hrtime(true) / 1e9 > $this->expiresAt) {
            throw new Error("Query exceeded the execution time limit of {$this->seconds} seconds.");
        }
    }
}
