<?php

namespace App\Support;

class GateCodes
{
    /**
     * Gate codes split evenly between terminal A and B: A1..A10, B1..B10 for 20 gates.
     *
     * @return list<array{code: string, terminal: string}>
     */
    public static function generate(int $count): array
    {
        $perTerminal = (int) ceil($count / 2);
        $gates = [];

        for ($i = 0; $i < $count; $i++) {
            $terminal = $i < $perTerminal ? 'A' : 'B';
            $number = $i < $perTerminal ? $i + 1 : $i - $perTerminal + 1;
            $gates[] = ['code' => $terminal.$number, 'terminal' => $terminal];
        }

        return $gates;
    }
}
