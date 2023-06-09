<?php

namespace AjdVal\Expression;

use AjdVal\Utils\Memoizer;
use AjdVal\Utils\Serializer;
use AjdVal\Utils\Utils;
use InvalidArgumentException;

class ExpressionEngine
{
    /**
     * Returns a valid rule name as per convention.
     *
     * @param string $name Rule name, preferably in camelCase.
     *
     * @return string The rule name like (`namespace.ruleName`).
     */
    public static function createRuleName(string $name): string
    {
        /*
         * (1) rule namespace is by convention in lowercase
         * (2) rule name is by convention in camelCase
         * (3) rule FQN is by convention combined using dots
         *
         * (1) -> "namespaceRuleName" or "ruleName"
         * (2) -> "namespace.rule.name" or "rule.name"
         * (3) -> "namespace.ruleName" or "ruleName"
         */
         
        $name  = Utils::transform($name, 'dot');    
        $parts = explode('.', $name, 2);
        $parts = [
            Utils::transform($parts[0] ?? '', 'lower'),
            Utils::transform($parts[1] ?? '', 'camel'),
        ];

        $name = trim(implode('.', $parts), '.');
        
        return $name;
    }

    /**
     * Returns valid rule arguments by stringifing the passed array.
     *
     * @param array<mixed> $arguments Rule arguments.
     *
     * @return string The stringified rule arguments.
     */
    public static function createRuleArguments(array $arguments): string
    {
        // back references are:
        // strings that contain an injectable between double quotes ("${data.validations.validation}")
        $backReference = '/(?:"\$\{(?:@?[a-z0-9_\-\.]+?)\}")/S';

        // escapable arguments are:
        // strings that contain "~, &, |, ^, (, )" (bitwise operators) and JSON-like strings
        $escapable = '/(?:(?:[\~\&\|\^\(\)])+|^(?:[\[\{])(.*)(?:[\}\]])$)/S';

        foreach ($arguments as $index => $argument) {
            $argument = Serializer::serialize($argument);

            $isBackReference = preg_match($backReference, $argument) === 1;
            $isEscapable     = preg_match($escapable, $argument) === 1;

            $arguments[$index] = match (true) {
                // remove double quotes from back references as they are not normal strings
                $isBackReference => trim($argument, '"'),
                // add escaping single quotes and backslashes to minimize CSV parsing errors
                $isEscapable     => sprintf("'%s'", addcslashes($argument, "'")),
                // return the stringified argument as is
                default          => $argument,
            };
        }

        $arguments = implode(',', $arguments);

        return $arguments;
    }

    /**
     * Returns a valid rule statement from the passed name and arguments.
     *
     * @param string $name Rule name. The name may need to be passed to `self::createRuleName()` first.
     * @param string $arguments [optional] Rule arguments. The arguments may need to be passed to `self::createRuleArguments()` first.
     *
     * @return string The rule statement.
     */
    public static function createRuleStatement(string $name, string $arguments = ''): string
    {
        $statement = trim(sprintf('%s:%s', $name, $arguments), ':');

        return $statement;
    }

    /**
     * Parses the rule statement by extracting rule name and rule arguments.
     *
     * @param string $statement Rule statement string.
     * @param array<int,string> $casts Rule arguments data-types casts.
     *
     * @return array<string,string|array<mixed>> A symbol as an associative array
     *      containing `name` and `arguments` (arguments will be in casted to their expected data-types).
     */
    public static function parseRule(string $statement, array $casts): array
    {
        $cacheKey = sprintf('%s;<%s>', $statement, implode(',', $casts));

        if (Memoizer::pool(__METHOD__)->has($cacheKey)) {
            return Memoizer::pool(__METHOD__)->get($cacheKey);
        }

        [$name, $arguments] = [$statement, []];

        if (strpos($statement, ':') !== false) {
            [$name, $arguments] = explode(':', $statement, 2);
            // treat the arguments as CSV to take advantage of CSV parsing capabilities
            $arguments = str_getcsv($arguments, ',', '\'', '\\');
        }

        // trim casts and reindex the array (in case it's associative)
        $casts = array_map('trim', $casts);
        $casts = array_values($casts);

        // trim arguments and reindex the array (in case it gets spread later)
        $arguments = array_map('trim', $arguments);
        $arguments = array_values($arguments);
        // fill missing arguments with an empty string (arguments count must match casts count)
        $arguments = array_pad($arguments, count($casts), '');

        foreach ($casts as $offset => $cast) {
            // normalize type name by dots
            $type = trim($cast, '.');

            // if type is variadic
            if (strpos($cast, '...') === 0) {
                // splice the rest of the arguments into a new array
                $rest = array_splice($arguments, $offset);
                // set types to spliced arguments
                $rest = array_map(fn ($variable) => Serializer::unserialize($variable, $type), $rest);

                // put the spliced array into the current index
                $arguments[$offset] = $rest;

                break;
            }

            // set argument type
            $arguments[$offset] = Serializer::unserialize($arguments[$offset], $type);
        }

        $result = [
            'name'      => $name,
            'arguments' => $arguments,
        ];

        Memoizer::pool(__METHOD__)->set($cacheKey, $result);

        return $result;
    }

    /**
     * Cleans (minifies) the validation expression by removing comments and unnecessary whitespace from it.
     *
     * @param string $expression Validation expression string.
     *
     * @return string The cleaned validation expression.
     */
    public static function cleanExpression(string $expression): string
    {
        $cacheKey = md5($expression);

        if (Memoizer::pool(__METHOD__)->has($cacheKey)) {
            return Memoizer::pool(__METHOD__)->get($cacheKey);
        }

        $patterns = [
            // search => replacement
            '/(?:"[^"]*"(*SKIP)(*FAIL)|(?:(?:#|\/\/)[^\r\n]*))/m' => '', // inline comments
            '/(?:"[^"]*"(*SKIP)(*FAIL)|(?:\/\*.*?\*\/))/s'        => '', // multiline comments
            '/(?:"[^"]*"(*SKIP)(*FAIL)|(?:\s+))/'                 => '', // whitespace
        ];

        $result = preg_replace(
            array_keys($patterns),
            array_values($patterns),
            $expression
        );

        Memoizer::pool(__METHOD__)->set($cacheKey, $result);

        return $result;
    }

    /**
     * Parses the validation expression by extracting the validations into an array of checks.
     *
     * @param string $expression Validation expression string.
     *
     * @return array<array<string,string>> An array of arrays where each of the nested arrays contains rule name and rule statement.
     */
    public static function parseExpression(string $expression): array
    {
        $expression = ltrim($expression, '!?'); // remove behavior if there is any

        $cacheKey = md5($expression);

        if (Memoizer::pool(__METHOD__)->has($cacheKey)) {
            return Memoizer::pool(__METHOD__)->get($cacheKey);
        }

        // replace JSON-like strings with temporary placeholders to prevent conflicts
        // with rule arguments escaping quotes and splitting characters, this is done because
        // JSON can be very complex and mess with the splitting of rules done later
        $placeholders = [];
        $expression   = preg_replace_callback(
            '/((?:[\[\{])(?:.*?)(?:[\}\]]))/S',
            function ($matches) use (&$placeholders) {
                $value = $matches[1];
                $hash  = md5($value);
                $key   = "%{$hash}%";

                $placeholders[$key] = $value;

                return $key;
            },
            $expression
        );

        // split the validation string by the following characters list:
        // "~ & | ^ ( )" (bitwise operators and precedence parentheses)
        // which are not inside balanced single quotes (escaped)
        $rulesRegex  = "/[\&\|\^\(\)](?=([^']*['][^']*['])*[^']*$)/u";
        $rulesString = $expression;
        $rulesArray  = preg_split($rulesRegex, $rulesString, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result      = array_map(fn ($rule) => [
            // extract name and statement and inject placeholders back
            'name'      => trim(strstr($rule, ':', true) ?: $rule),
            'statement' => trim(strtr(str_replace('~', '', $rule), $placeholders)),
        ], $rulesArray);

        Memoizer::pool(__METHOD__)->set($cacheKey, $result);

        return $result;
    }

    /**
     * Evaluates the passed validation expression string using the provided statements results.
     *
     * @param string $expression The validation expression to evaluate.
     * @param array<string,bool> $results Statements results. An associative array where key is the statement and value is the result.
     * @param bool $multi if result is a list
     *
     * @return array<string,string|bool> An associative array containing the resulted bitwise expression and its result.
     *
     * @throws InvalidArgumentException If the expression resulted in an invalid bitwise expression.
     * @throws InvalidArgumentException If an infinite loop is detected while resolving the expression.
     *
     * @since 1.1.0
     */
    public static function evaluateExpression(string $expression, array $results, bool $multi = false): array
    {
        $realResult = [];

        if ($multi) {
            foreach ($results as $key => $resultList) {
                foreach ($resultList as $statement => $result) {
                    $realResult[$key][$statement] = $result = (string)(int)(bool)($result);

                    $expression = substr_replace($expression, $result, intval(strpos($expression, $statement)), strlen($statement));
                }
            }
        } else {
            $key = 0;
            foreach ($results as $statement => $result) {
                // (string)(int)(bool) is used to cast to a bit ('0' or '1')
                $realResult[$key][$statement] = $result = (string)(int)(bool)($result);

                // replace the rule (statement) with its result (bit) to build up the bitwise expression
                // here only the first occurrence of the rule (statement) will be replaced because some rules
                // can be a substring of other rules, which will mess up the expression and render it useless
                $expression = substr_replace($expression, $result, intval(strpos($expression, $statement)), strlen($statement));
                $key++;
            }
        }

        // the loop above will replace only the first occurrence of the rule
        // sometimes the same rule is added more than once, this should never happen
        // but to mitigate that error, replace any left over rules in the expression
        // with their corresponding bits (using the cached $bits array)

        foreach ($realResult as $results) {
            $expression = strtr($expression, $results);
        }
        
        $result     = static::evaluateBitwiseExpression($expression);

        return compact('expression', 'result');
    }

    /**
     * Evaluates a bitwise expression string and returns its boolean result.
     *
     * NOTE: The expression may only contain the following characters: `0`, `1`, `~`, `&`, `|`, `^`, `(`, `)`.
     *      Which are the subset of Bitwise that is the same as Boolean Algebra (returns a boolean value).
     *      The expression may also have whitespace characters (spaces, tabs, new lines), these will simply be discarded.
     *
     * @param string $expression The bitwise expression to evaluate.
     *
     * @return bool The result of the evaluation.
     *
     * @throws InvalidArgumentException If the expression is not a valid bitwise expression (as per `AjdVal` specification).
     * @throws InvalidArgumentException If an infinite loop is detected while resolving the expression.
     */
    public static function evaluateBitwiseExpression(string $expression): bool
    {
        $cacheKey = $expression = preg_replace('/\s/', '', $expression); // normalize

        if (Memoizer::pool(__METHOD__)->has($cacheKey)) {
            return Memoizer::pool(__METHOD__)->get($cacheKey);
        }

        $bitwise = self::getBitwiseTranslationsMap();

        if (isset($bitwise[$expression])) {
            $result = (bool)(int)$bitwise[$expression];

            Memoizer::pool(__METHOD__)->set($cacheKey, $result);

            return $result;
        }

        $checks = [
            'expression string is empty' =>
                preg_match('/^$/S', $expression) === 1,
            'contains characters other than ["0", "1", "~", "&", "|", "^", "(", ")"]' =>
                preg_match('/(?:[^01\~\&\|\^\(\)]+)/S', $expression) === 1,
            'starts with an operator like ["&", "|", "^"] or ends with an operator like ["~", "&", "|", "^"]' =>
                preg_match('/(?:^[&|^])|(?:[~&|^]$)/S', $expression) === 1,
            'an operator like ["&", "|", "^"] is repeated more than once consecutively' =>
                preg_match('/(?:[&|^]{2,})/S', $expression) === 1,
            'precedence parentheses ["(", ")"] are not balanced' =>
                preg_match('/(?:[\(\)])/S', $expression) === 1 && substr_count($expression, '(') !== substr_count($expression, ')'),
        ];

        if (in_array(true, $checks, true)) {
            $problems = implode(', ', array_keys(array_filter($checks)));

            throw new InvalidArgumentException(
                "Invalid bitwise expression: [{$expression}]. Problem(s): {$problems}"
            );
        }

        $result    = $expression;
        $microtime = microtime(true);

        while (strlen($result) !== 1) {
            $result = strtr($result, $bitwise);

            // break the loop if the expression is not evaluated within 1 second
            if (microtime(true) - $microtime >= 1) {
                throw new InvalidArgumentException(
                    "Invalid bitwise expression: [{$expression}]. Result: [{$result}]. Infinite loop detected"
                );
            }
        }

        $result = (bool)(int)$result;

        Memoizer::pool(__METHOD__)->set($cacheKey, $result);

        return $result;
    }

    /**
     * @return array<int|string,string>
     */
    private static function getBitwiseTranslationsMap(): array
    {
        return [
            // normal
            '1'   => '1',
            '0'   => '0',
            // not
            '~0'  => '1',
            '~1'  => '0',
            // parenthesis
            '(0)' => '0',
            '(1)' => '1',
            // and
            '0&0' => '0',
            '1&0' => '0',
            '0&1' => '0',
            '1&1' => '1',
            // or
            '0|0' => '0',
            '1|0' => '1',
            '0|1' => '1',
            '1|1' => '1',
            // xor
            '0^0' => '0',
            '1^0' => '1',
            '0^1' => '1',
            '1^1' => '0',
        ];
    }
}