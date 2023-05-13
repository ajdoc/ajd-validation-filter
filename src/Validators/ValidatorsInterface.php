<?php 

namespace AjdVal\Validators;

use AjDic\AjDic;

interface ValidatorsInterface
{
	/**
     * Sets if the validator will use \AjdVal\Expression\ExpressionBuilderValidator or not.
     *
     * @param  bool  $send
     * @return void
     */
    public static function setSendToExpressionValidator(bool $send): void;

    /**
     * Get sendToExpressionValidator static property.
     *
     * @return bool
     */
    public static function getSendToExpressionValidator(): bool;
}