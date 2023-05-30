<?php 

namespace AjdVal\Handlers;

use AjdVal\Expression\ExpressionBuilderValidator;
use AjdVal\Validators\ValidatorsInterface;
use Stringable;

class ExprHandler extends AbstractHandlers
{
	public function __construct(
		ExpressionBuilderValidator $expressionBuilder,
		ValidatorsInterface $validator,
		array $definitions = []

	) {
		$this->expressionBuilder = $expressionBuilder;
		$this->validator = $validator;
		$this->definitions = $definitions;
	}
}