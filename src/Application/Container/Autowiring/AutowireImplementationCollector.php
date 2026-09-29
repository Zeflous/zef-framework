<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (autowiring compile pass).
 *
 * Implementation-collection collaborator of the autowiring compile pass:
 * gathers every registered service that satisfies a requested type — FQCN
 * service IDs matching via is_a, plus factory closures with a declared,
 * compatible return type. Extracted from AutowireCompilerPass in the
 * sonar-zero campaign (behavior-preserving split).
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Container\Container;

final class AutowireImplementationCollector
{
    /**
     * Collect every registered service that satisfies the given type.
     * Sources: FQCN service IDs (is_a match) and factory closures with a
     * declared, compatible return type. Registration order is preserved.
     *
     * @return list<string>
     */
    public function collect(Container $container, string $type): array
    {
        $ids = [];
        foreach ($container->getRegistry()->definitions() as $id => $definition) {
            if (str_starts_with($id, AutowireCompilerPass::VALUE_SERVICE_PREFIX)) {
                continue;
            }
            // @infection-ignore-all LogicalOrAllSubExprNegation — ekuivalen: negasi ganda identik
            // untuk id class/interface/bukan-keduanya; is_a menutup sisa kasus
            if ((class_exists($id) || interface_exists($id)) && is_a($id, $type, true)) {
                $ids[] = $id;

                continue;
            }
            $returnType = $this->factoryReturnType($definition->factory);
            if ($returnType !== null && is_a($returnType, $type, true)) {
                $ids[] = $id;
            }
        }

        // @infection-ignore-all UnwrapArrayUnique,UnwrapArrayValues — ekuivalen: id dari kunci map
        // terkumpul paling sekali; kunci numerik sudah berurutan
        return array_values(array_unique($ids));
    }

    private function factoryReturnType(mixed $factory): ?string
    {
        try {
            $fn = $factory instanceof \Closure ? $factory : \Closure::fromCallable($factory);
            $returnType = new \ReflectionFunction($fn)->getReturnType();
        } catch (\Throwable) {
            return null;
        }
        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
            return $returnType->getName();
        }

        return null; // no declared return type / builtin / union: not inferable
    }
}
