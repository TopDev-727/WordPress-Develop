<?php
/*
 * This file is part of PHPUnit.
 *
 * This file is modified to replace the Match interface with ParametersMatch
 * for PHP 8 compatibility.
 * 
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework\MockObject\Builder;

use PHPUnit\Framework\MockObject\Matcher\AnyParameters;

/**
 * Builder interface for parameter matchers.
 */
interface ParametersMatch extends Stub
{
    /**
     * Defines the expectation which must occur before the current is valid.
     *
     * @param string $id the identification of the expectation that should
     *                   occur before this one
     *
     * @return Stub
     */
    public function after($id);

    /**
     * Sets the parameters to match for, each parameter to this function will
     * be part of match. To perform specific matches or constraints create a
     * new PHPUnit\Framework\Constraint\Constraint and use it for the parameter.
     * If the parameter value is not a constraint it will use the
     * PHPUnit\Framework\Constraint\IsEqual for the value.
     *
     * Some examples:
     * <code>
     * // match first parameter with value 2
     * $b->with(2);
     * // match first parameter with value 'smock' and second identical to 42
     * $b->with('smock', new PHPUnit\Framework\Constraint\IsEqual(42));
     * </code>
     *
     * @return ParametersMatch
     */
    public function with(...$arguments);

    /**
     * Sets a matcher which allows any kind of parameters.
     *
     * Some examples:
     * <code>
     * // match any number of parameters
     * $b->withAnyParameters();
     * </code>
     *
     * @return AnyParameters
     */
    public function withAnyParameters();
}
