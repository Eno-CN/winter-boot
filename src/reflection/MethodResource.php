<?php

declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\support\MethodParameters;
use dev\winterframework\reflection\support\ParameterType;
use dev\winterframework\type\AttributeList;

class MethodResource {
    private ?ClassResource $returnClass;

    private ParameterType $returnType;

    private RefMethod $method;

    private MethodParameters $parameters;

    private AttributeList $attributes;

    private bool $proxyNeeded = false;

    public function getAttribute(string $name): ?object {
        return isset($this->attributes) ? $this->attributes->getByName($name) : null;
    }

    public function getReturnNamedType(): ParameterType {
        if (!isset($this->returnType)) {
            $this->returnType = ParameterType::fromType($this->method->getReturnType());
        }
        return $this->returnType;
    }

    public function getReturnType(): string {
        return $this->getReturnNamedType()->getName();
    }

    public function getReturnClass(): ?ClassResource {
        return $this->returnClass;
    }

    public function setReturnClass(?ClassResource $returnClass): void {
        $this->returnClass = $returnClass;
    }

    public function getMethod(): RefMethod {
        return $this->method;
    }

    public function setMethod(RefMethod $method): void {
        $this->method = $method;
    }

    public function getAttributes(): AttributeList {
        return $this->attributes;
    }

    public function setAttributes(AttributeList $attributes): void {
        $this->attributes = $attributes;
    }

    public function isProxyNeeded(): bool {
        return $this->proxyNeeded;
    }

    public function setProxyNeeded(bool $proxyNeeded): void {
        $this->proxyNeeded = $proxyNeeded;
    }

    public function getParameters(): MethodParameters {
        return $this->parameters;
    }

    public function setParameters(MethodParameters $parameters): void {
        $this->parameters = $parameters;
    }

}