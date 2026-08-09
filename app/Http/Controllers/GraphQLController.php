<?php

namespace App\Http\Controllers;

use App\GraphQL\ExecutionDeadline;
use App\GraphQL\StorefrontSchema;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Single GraphQL endpoint for the storefront. Optional auth: the catalog is public, but
 * me/orders and every mutation need a Sanctum token — the authenticated user (or null)
 * is resolved here and handed to resolvers as $context['user'].
 *
 * Three bounds on the cost of one request, because this route is public and
 * anonymous. Depth and complexity bound the *shape* of a query; the deadline
 * bounds its *time*, which the other two cannot — a query can sit inside both
 * and still hold a worker for as long as the database takes. They live in
 * `config/graphql.php` so a deployment can tighten them without a release.
 */
class GraphQLController extends Controller
{
    public function __invoke(Request $request, StorefrontSchema $schema): JsonResponse
    {
        $query = $request->input('query');
        if (! is_string($query) || trim($query) === '') {
            return response()->json(['errors' => [['message' => 'No GraphQL query provided.']]], 400);
        }

        $variables = $request->input('variables');
        $context = ['user' => auth('sanctum')->user()];

        $rules = array_merge(GraphQL::getStandardValidationRules(), [
            new QueryComplexity((int) config('graphql.max_complexity')),
            new QueryDepth((int) config('graphql.max_depth')),
        ]);

        try {
            $built = $schema->build();

            // Built per request, so the clock starts here rather than at boot.
            $deadline = new ExecutionDeadline((float) config('graphql.execution_timeout'));

            $result = GraphQL::executeQuery(
                schema: $built,
                source: $query,
                contextValue: $context,
                variableValues: is_array($variables) ? $variables : null,
                operationName: $request->input('operationName'),
                fieldResolver: $deadline->guard($built),
                validationRules: $rules,
            );

            $debug = config('app.debug') ? DebugFlag::INCLUDE_DEBUG_MESSAGE : DebugFlag::NONE;

            return response()->json($result->toArray($debug));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['errors' => [['message' => 'Internal server error.']]], 500);
        }
    }
}
