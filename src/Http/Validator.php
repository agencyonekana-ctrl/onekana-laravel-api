<?php

namespace Onekana\Api\Http;

final class Validator
{
    public static function require(array $data, array $rules): void
    {
        $errors = [];

        foreach ($rules as $field => $ruleSet) {
            $value = $data[$field] ?? null;
            foreach ($ruleSet as $rule) {
                if ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = 'Le champ '.$field.' est obligatoire.';
                }

                if ($rule === 'email' && $value !== null && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = 'Le champ '.$field.' doit etre une adresse email valide.';
                }

                if ($rule === 'string' && $value !== null && ! is_string($value)) {
                    $errors[$field][] = 'Le champ '.$field.' doit etre une chaine.';
                }
            }
        }

        if ($errors !== []) {
            throw new HttpException(422, 'The given data was invalid.', $errors);
        }
    }
}
