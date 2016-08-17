<?php

/**
 * Copyright (C) 2016 Datto, Inc.
 *
 * This file is part of Php Type Inferer.
 *
 * Php Type Inferer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * Php Type Inferer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with Php Type Inferer. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Anthony Liu <igliu@mit.edu>
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL-3.0
 * @copyright 2016 Datto, Inc.
 * @version 0.3.5
 */

namespace Datto\PhpTypeInferer;

class TypeInferer
{
    private $signatures;
    private $signatureDictionary;

    public function __construct($signatures)
    {
        $this->signatures = $signatures;

        // expand the flattened signatures into a hierarchical, associative array
        $this->signatureDictionary = array();
        foreach ($signatures as $name => $overloadList) {
            $this->signatureDictionary[$name] = array();

            foreach ($overloadList as $key => $signature) {
                $reference = &$this->signatureDictionary[$name];
                for ($s = 0; $s < count($signature['arguments']); $s++) {
                    $currentType = $signature['arguments'][$s];
                    if (!array_key_exists($currentType, $reference)) {
                        $reference[$currentType] = array();
                    }
                    $reference = &$reference[$currentType];
                }
                $reference = $signature['return'];
            }
        }
    }

    public function infer($expressions)
    {
        if (count($expressions) === 0) {
            return array('ordering' => array(), 'hierarchy' => true);
        }

        // get the valid settings for each expression
        $settingsLists = array();
        foreach ($expressions as $key => $expression) {
            $typeSettings = $this->inferExpression($expression);

            // transform the data into a workable form
            $flattenedTypeSettings = array();
            foreach ($typeSettings as $returnType => $settings) {
                // remove the expression's return type information for the resolution step
                $flattenedTypeSettings = array_merge($flattenedTypeSettings, $settings);
            }
            
            $settingsLists[] = $flattenedTypeSettings;
        }

        // resolve inconsistencies between the valid settings of each expression
        $resolutionInconsistencies = array();
        $validSettings = $this->resolveInconsistentSettings($settingsLists, $resolutionInconsistencies);
        if (count($validSettings) === 0) {
            throw $resolutionInconsistencies[0];
        }

        // build a structure to support efficient consistency verification (as opposed to an O(1) scan)
        $lookupStructure = $this->getHierarchyFromList($validSettings);

        return $lookupStructure;
    }

    private function inferExpression($expression)
    {
        // disambiguate the function and parameter names by appending unique ids to names
        $this->disambiguate($expression); // 0 is the initial id

        // construct a constraints dictionary
        $this->populateConstraints($expression, $constraints);

        // resolve the constraints (bottom up) to generate the list of valid types
        $inconsistencies = array();
        $typeSettings = $this->reconstruct($expression['name'], $constraints, $expression, $inconsistencies);

        if (count(array_keys($typeSettings)) === 0) {
            throw $inconsistencies[0];
        }

        return $typeSettings;
    }

    private function disambiguate(&$expression, $id = 0)
    {
        $expression['name'] = $expression['name'] . '#' . $id;
        $id += 1;
        if ($expression['type'] === 'function') {
            for ($s = 0; $s < count($expression['arguments']); $s++) {
                $id = $this->disambiguate($expression['arguments'][$s], $id);
            }
        }
        return $id;
    }

    private function populateConstraints($expression, &$constraints)
    {
        if (!isset($constraints)) {
            $constraints = array();
        }

        if ($expression['type'] === 'parameter') {
            return false;
        } elseif ($expression['type'] === 'function') {
            $expressionName = $expression['name'];
            $functionName = $this->getNameFromIdentifier($expression['name']);
            $signatures = $this->signatures[$functionName];

            // get the type restrictions imposed by previous function calls
            $callArity = count($expression['arguments']); // the arity of this specific call
            $typeRestrictions = $this->getTypeRestrictions($constraints, $expressionName); // null or array of types

            // filter out the signatures of this function by 1) arity and 2) type restrictions
            $filteredSignatures = $this->filterSignatures($signatures, $callArity, $typeRestrictions);

            // filter by primitives
            $viableSignatures = $this->filterSignaturesByPrimitives($expression, $filteredSignatures);

            // throw an exception when there are no valid signatures
            if (count($viableSignatures) === 0) {
                throw new InconsistentTypeException(array(
                    'type' => 'function',
                    'name' => $expression['name'],
                    'signatures' => $signatures
                ));
            }
            
            // record the viable signatures in the constraints entries for each child
            foreach ($viableSignatures as $key => $signature) {
                for ($c = 0; $c < count($expression['arguments']); $c++) {
                    $child = $expression['arguments'][$c];
                    $childName = $child['name'];
                    if (!array_key_exists($childName, $constraints)) {
                        $constraints[$childName] = array();
                    }
                    $childConstraint = &$constraints[$childName];

                    // create nested associate arrays for each previous argument
                    for ($s = 0; $s < $c; $s++) {
                        $parameterType = $signature['arguments'][$s];
                        if (!array_key_exists($parameterType, $childConstraint)) {
                            $childConstraint[$parameterType] = array();
                        }
                        $childConstraint = &$childConstraint[$parameterType];
                    }

                    // append a new type possibility to the innermost object
                    $childConstraint[$signature['arguments'][$c]] = true;
                }
            }

            // recurse on children
            for ($c = 0; $c < count($expression['arguments']); $c++) {
                $child = $expression['arguments'][$c];
                $this->populateConstraints($child, $constraints);
            }
        }
        
        return true;
    }

    private function getTypeRestrictions($constraints, $expressionName)
    {
        $typeRestrictions = array();
        if (array_key_exists($expressionName, $constraints)) {
            // recursively accumulate the keys of boolean terminals from the constraints into an array
            $this->searchConstraintsForTerminals($constraints[$expressionName], $typeRestrictions);
        }
        return array_keys($typeRestrictions);
    }

    private function searchConstraintsForTerminals($constraints, &$terminalTypes)
    {
        foreach ($constraints as $type => $value) {
            if (gettype($value) === 'boolean') {
                $terminalTypes[$type] = true;
            } else {
                $this->searchConstraintsForTerminals($constraints[$type], $terminalTypes);
            }
        }
    }

    private function filterSignatures($viableSignatures, $callArity, $typeRestrictions)
    {
        // always filter by arity
        $viableSignatures = array_filter(
            $viableSignatures,
            function ($signature) use ($callArity) {
                return $callArity === count($signature['arguments']);
            }
        );

        // if there are restrictions, filter by types as well
        if (count($typeRestrictions) !== 0) {
            $viableSignatures = array_filter(
                $viableSignatures,
                function ($signature) use ($typeRestrictions) {
                    // the signature's return type must be in the typeRestrictions array
                    $returnType = $signature['return'];
                    return array_search($returnType, $typeRestrictions) !== false;
                }
            );
        }

        return $viableSignatures;
    }

    private function filterSignaturesByPrimitives($expression, $signatures)
    {
        $primitiveArguments = array();
        foreach ($expression['arguments'] as $index => $argument) {
            if ($argument['type'] === 'primitive') {
                $primitiveArguments[] = array(
                    'index' => $index,
                    'name' => $argument['name']
                );
            }
        }
        $viableSignatures = array();
        foreach ($signatures as $signature) {
            $conformsToPrimitives = true;
            foreach ($primitiveArguments as $primitive) {
                $type = explode('#', $primitive['name'])[0];
                if (strval($signature['arguments'][$primitive['index']]) !== strval($type)) {
                    $conformsToPrimitives = false;
                    break;
                }
            }
            if ($conformsToPrimitives) {
                $viableSignatures[] = $signature;
            }
        }

        return $viableSignatures;
    }

    private function reconstruct($parentFunctionName, $constraints, $expression, &$error)
    {
        // base case: parameters
        if ($expression['type'] === 'parameter') {
            return $this->reconstructParameter(
                $expression['name'],
                $constraints[$expression['name']]
            );
        } elseif ($expression['type'] === 'primitive') {
            return $this->reconstructPrimitive(
                $expression['name'],
                $constraints[$expression['name']]
            );
        } else {
            // first, reconstruct all the children
            $functionName = $this->getNameFromIdentifier($expression['name']);
            $reconstructedKids = array();
            for ($c = 0; $c < count($expression['arguments']); $c++) {
                $child = $expression['arguments'][$c];
                $reconstructedKid = $this->reconstruct($functionName, $constraints, $child, $error);
                if ($reconstructedKid !== false) {
                    $reconstructedKids[] = $reconstructedKid;
                }
            }

            // compute the raw product (indexed by return type)
            $rawProduct = $this->getSiblingProduct(
                $this->signatureDictionary[$functionName],
                $reconstructedKids,
                $error
            );

            if (!array_key_exists($expression['name'], $constraints)) {
                // if there are no constraints on this expression, it's at the top and this format is good
                return $rawProduct;
            } else {
                // otherwise, transform the raw product to the intermediate form, indexed by the previous child
                $reconstruction = array();
                foreach ($rawProduct as $returnType => $settings) {
                    for ($i = 0; $i < count($settings); $i++) {
                        $reconstruction[] = array(
                            'settings' => $settings[$i],
                            'return' => $returnType
                        );
                    }
                }

                return $this->reconstructFunction(
                    $constraints[$expression['name']],
                    $this->signatureDictionary[$parentFunctionName],
                    $reconstruction
                );
            }
        }
    }

    private function reconstructParameter($name, $constraint)
    {
        $reconstruction = array();
        foreach ($constraint as $type => $hierarchy) {
            if (gettype($constraint[$type]) === 'boolean') {
                $reconstruction[] = array(
                  'settings' => array($this->getNameFromIdentifier($name) => $type),
                  'return' => $type
                );
            } else {
                $reconstruction[$type] = $this->reconstructParameter(
                    $name,
                    $constraint[$type]
                );
            }
        }
        return $reconstruction;
    }

    private function reconstructPrimitive($name, $constraint)
    {
        $reconstruction = array();
        foreach ($constraint as $type => $hierarchy) {
            if (gettype($constraint[$type]) === 'boolean') {
                $reconstruction[] = array(
                  'settings' => 'primitive',
                  'return' => $type
                );
            } else {
                $reconstruction[$type] = $this->reconstructPrimitive(
                    $name,
                    $constraint[$type]
                );
            }
        }
        return $reconstruction;
    }

    private function reconstructFunction($constraint, $signature, $value)
    {
        $reconstruction = array();
        foreach ($constraint as $type => $hierarchy) {
            if (gettype($constraint[$type]) === 'boolean') {
                $reconstruction = $this->filterSettingsListBySignature($value, $signature);
            } else {
                $reconstruction[$type] = $this->reconstructFunction(
                    $constraint[$type],
                    $signature[$type],
                    $value
                );
            }
        }
        return $reconstruction;
    }

    private function filterSettingsListBySignature($settings, $signature)
    {
        return array_values(array_filter(
            $settings,
            function ($setting) use ($signature) {
                return array_key_exists($setting['return'], $signature);
            }
        ));
    }

    private function getSiblingProduct($signature, $siblings, &$error)
    {
        $product = array();
        if (count($siblings) === 1) {
            foreach ($siblings[0] as $key => $configuration) {
                $returnType = $signature[$configuration['return']];
                if (!array_key_exists($returnType, $product)) {
                    $prod[$returnType] = array();
                }
                if ($configuration['settings'] === 'primitive') {
                    $product[$returnType][] = array();
                } else {
                    $product[$returnType][] = $configuration['settings'];
                }
            }
            return $product;
        } else {
            foreach ($siblings[0] as $key => $configuration) {
                // offset the rest of the children by this config's return
                $offsetSiblings = array();
                for ($o = 1; $o < count($siblings); $o++) {
                    $offsetSiblings[] = $siblings[$o][$configuration['return']];
                }

                // offset the signature as well
                $offsetSignature = $signature[$configuration['return']];

                // partial sibling product
                $partialProduct = $this->getSiblingProduct($offsetSignature, $offsetSiblings, $error);

                if ($configuration['settings'] !== 'primitive') {
                    $product = $this->consolidate(
                        $product,
                        $this->cartesianProduct(
                            $partialProduct,
                            $configuration['settings'],
                            $error
                        )
                    );
                } else {
                    $product = $partialProduct;
                }
            }
            return $product;
        }
    }

    private function consolidate($a, $b)
    {
        $object = array();
        foreach ($a as $keyA => $valueA) {
            $object[$keyA] = $a[$keyA];
        }
        foreach ($b as $keyB => $valueB) {
            if (array_key_exists($keyB, $object)) {
                $object[$keyB] = array_merge($object[$keyB], $b[$keyB]); // numeric keys; appends elements
            } else {
                $object[$keyB] = $b[$keyB];
            }
        }
        return $object;
    }

    private function cartesianProduct($labeledSet, $incrementalSet, &$error)
    {
        $product = array();
        foreach ($labeledSet as $returnType => $settings) {
            foreach ($settings as $key => $setting) {
                try {
                    if ($setting === 'primitive') {
                        if (!array_key_exists($returnType, $product)) {
                            $product[$returnType] = array();
                        }
                        $product[$returnType][] = $incrementalSet;
                    } else {
                        $mergedSets = $this->merge($setting, $incrementalSet);

                        if (!array_key_exists($returnType, $product)) {
                            $product[$returnType] = array();
                        }
                        $product[$returnType][] = $mergedSets;
                    }
                } catch (InconsistentTypeException $e) {
                    $error[] = $e;
                }
            }
        }
        return $product;
    }

    private function getNameFromIdentifier($id)
    {
        $index = strrpos($id, '#', -1);
        $disambiguatedId = $id;
        if ($index !== false) {
            $disambiguatedId = substr($id, 0, $index);
        }
        return $disambiguatedId;
    }

    private function getHierarchyFromList($list)
    {
        $structure = array();

        if (count($list) > 0) {
            $structure['ordering'] = array_keys($list[0]); // the very first parameter setting
            $structure['hierarchy'] = array();
            foreach ($list as $key => $setting) {
                $this->insertSettingIntoHierarchy($setting, $structure['ordering'], $structure['hierarchy']);
            }
        }

        return $structure;
    }

    private function insertSettingIntoHierarchy($setting, $ordering, &$hierarchy)
    {
        if (count($ordering) === 0) {
            $hierarchy = true;
            return;
        }

        $type = $setting[$ordering[0]];
        if (!array_key_exists($type, $hierarchy)) {
            $hierarchy[$type] = array();
        }
        $this->insertSettingIntoHierarchy($setting, array_slice($ordering, 1), $hierarchy[$type]);
    }

    private function resolveInconsistentSettings($typeSettingsLists, &$errors)
    {
        // resolve inconsistencies between the sets
        $consistentSet = $typeSettingsLists[0];
        for ($i = 1; $i < count($typeSettingsLists); $i++) {
            $consistentSet = $this->relaxSets($consistentSet, $typeSettingsLists[$i], $errors);
            if (count($consistentSet) === 0) {
                break;
            }
        }

        return $consistentSet;
    }

    private function relaxSets($setA, $setB, &$errors)
    {
        $childSet = array();

        // iterate through all pairs
        foreach ($setA as $keyA => $settingA) {
            foreach ($setB as $keyB => $settingB) {
                try {
                    $childSet[] = $this->merge($settingA, $settingB);
                } catch (InconsistentTypeException $e) {
                    $errors[] = $e;
                }
            }
        }

        return $childSet;
    }

    private function merge($a, $b)
    {
        $object = array();
    
        foreach ($a as $keyA => $value) {
            $disambiguatedKeyA = $this->getNameFromIdentifier($keyA);
            $object[$disambiguatedKeyA] = $a[$keyA];
        }
    
        foreach ($b as $keyB => $value) {
            $disambiguatedKeyB = $this->getNameFromIdentifier($keyB);
            
            if (array_key_exists($disambiguatedKeyB, $object)) {
                $proposedType = $object[$disambiguatedKeyB];
                if ($proposedType !== $b[$keyB]) {
                    throw new InconsistentTypeException(array(
                        'name' => $disambiguatedKeyB,
                        'types' => array($proposedType, $b[$keyB])
                    ));
                }
            }

            $object[$disambiguatedKeyB] = $b[$keyB];
        }
    
        return $object;
    }
}
