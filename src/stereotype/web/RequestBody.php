<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\reflection\ref\RefParameter;
use dev\winterframework\stereotype\StereoType;
use dev\winterframework\type\TypeAssert;
use ReflectionNamedType;

#[Attribute(Attribute::TARGET_PARAMETER)]
class RequestBody implements StereoType {

    private string $variableType;
    private string $variableName;

    public function __construct(
        public string $name = '',
        public bool $required = true
    ) {
    }

    public function getVariableType(): string {
        return $this->variableType;
    }

    public function getVariableName(): string {
        return $this->variableName;
    }

    public function init(object $ref): void {
        /** @var RefParameter $ref */
        TypeAssert::typeOf($ref, RefParameter::class);

        if (trim($this->name) == '') {
            $this->name = $ref->getName();
        }

        if (!$ref->hasType()) {
            throw new InvalidSyntaxException(
                'Parameter annotated with #[RequestBody] must have TypeHinted, param: '
                . $ref->getName() . ' defined in method '
                . $ref->getDeclaringFunction()->getName()
            );
        }

        $this->variableName = $ref->getName();

        /** @var ReflectionNamedType $type */
        $type = $ref->getType();

        if ($type->isBuiltin()) {
            throw new InvalidSyntaxException(
                'Parameter annotated with #[RequestBody] must have TypeHinted with a custom class, param: '
                . $ref->getName() . ' defined in method '
                . $ref->getDeclaringFunction()->getName()
            );
        }

        $this->variableType = $type->getName();
        if (!class_exists($this->variableType)) {
            throw new InvalidSyntaxException(
                'Could not load/find the class '
                . $this->variableType
                . ' for Parameter annotated with #[RequestBody], param: '
                . $ref->getName() . ' defined in method '
                . $ref->getDeclaringFunction()->getName()
            );
        }
    }
}