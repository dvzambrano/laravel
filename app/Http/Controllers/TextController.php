<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class TextController extends Controller
{

    public function standardize($text)
    {
        // Convertir a minúsculas
        $text = mb_strtolower($text, 'UTF-8');
        // Eliminar tildes y acentos
        $text = strtr($text, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'ñ' => 'n',
            'Ñ' => 'N',
        ]);
        // Eliminar espacios adicionales
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text;
    }

    public function calculateSimilarityPercentage($text1, $text2)
    {
        // Normalizar los textos
        $text1 = $this->standardize($text1);
        $text2 = $this->standardize($text2);

        // Calcular la distance Levenshtein entre los textos
        $distance = levenshtein($text1, $text2);

        // Encontrar la longitud máxima entre ambos textos
        $max_length = max(strlen($text1), strlen($text2));

        // Si la longitud máxima es 0 (ambos textos vacíos), son 100% similares
        if ($max_length == 0) {
            return 100;
        }

        // Calcular el porcentaje de similitud basado en la distance Levenshtein
        $percentage = (1 - $distance / $max_length) * 100;

        return $percentage;
    }
    public function numberAsEmoji($number)
    {
        // Mapeo de dígitos a emojis (versión de Telegram)
        $map = [
            "0" => "0️⃣",
            "1" => "1️⃣",
            "2" => "2️⃣",
            "3" => "3️⃣",
            "4" => "4️⃣",
            "5" => "5️⃣",
            "6" => "6️⃣",
            "7" => "7️⃣",
            "8" => "8️⃣",
            "9" => "9️⃣",
            "." => "🔹",
            "-" => "➖"
        ];

        $string = (string) $number;
        $text = "";

        for ($i = 0; $i < strlen($string); $i++) {
            $char = $string[$i];

            if (isset($map[$char])) {
                $text .= $map[$char];
            } else {
                $text .= $char; // Si no hay emoji, mantener el carácter original
            }
        }

        return $text;
    }

    public function str_pad($input, $length, $pad_string = ' ', $pad_type = STR_PAD_RIGHT)
    {
        $input_length = strlen($input);

        // Si el $length es menor que la longitud del input y es un padding normal (no negativo)
        if ($length < $input_length && $pad_type >= 0) {
            return substr($input, 0, max($length - 3, 0)) . '...'; // Aseguramos que no sea negativo
        }

        // Si el $pad_type es negativo, rellenamos en el medio
        if ($pad_type < 0) {
            $words = explode(' ', $input, 2);
            if (count($words) < 2) {
                return str_pad($input, $length, $pad_string, STR_PAD_RIGHT);
            }

            $current_length = strlen($words[0]) + strlen($words[1]);
            $padding_needed = $length - $current_length;

            if ($padding_needed <= 0) {
                return $input; // No hay espacio para rellenar
            }

            return $words[0] . str_repeat($pad_string, $padding_needed) . $words[1];
        } else {
            // Comportamiento normal de str_pad()
            return str_pad($input, $length, $pad_string, $pad_type);
        }
    }

}
