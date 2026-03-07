<?php

namespace App\Services;

class C45Service
{
    /**
     * Menghitung nilai Entropy (Tingkat keacakan / ketidakpastian data)
     * Rumus: E(S) = sum( -p * log2(p) )
     */
    private function calculateEntropy(array $data, string $targetAttribute): float
    {
        $totalRows = count($data);
        if ($totalRows === 0) return 0;

        $labelCounts = array_count_values(array_column($data, $targetAttribute));
        $entropy = 0.0;

        foreach ($labelCounts as $count) {
            $probability = $count / $totalRows;
            $entropy -= $probability * log($probability, 2);
        }

        return $entropy;
    }

    /**
     * Menghitung Information Gain dari suatu atribut
     * Rumus: Gain(S, A) = Entropy(S) - sum( (|Sv| / |S|) * Entropy(Sv) )
     */
    private function calculateGain(array $data, string $attribute, string $targetAttribute, float $baseEntropy): float
    {
        $totalRows = count($data);
        $attributeValues = array_unique(array_column($data, $attribute));
        $subsetEntropy = 0.0;

        foreach ($attributeValues as $value) {
            $subset = array_filter($data, fn($row) => $row[$attribute] === $value);
            $subsetWeight = count($subset) / $totalRows;
            $subsetEntropy += $subsetWeight * $this->calculateEntropy($subset, $targetAttribute);
        }

        return $baseEntropy - $subsetEntropy;
    }

    /**
     * Mencari label mayoritas jika pohon harus berhenti (Leaf Node)
     */
    private function getMajorityLabel(array $data, string $targetAttribute): string
    {
        $labelCounts = array_count_values(array_column($data, $targetAttribute));
        arsort($labelCounts);
        return array_key_first($labelCounts);
    }

    /**
     * Membangun Decision Tree secara Rekursif
     */
    public function buildTree(array $data, array $attributes, string $targetAttribute)
    {
        $labels = array_column($data, $targetAttribute);
        $uniqueLabels = array_unique($labels);

        // Jika semua data memiliki label yang sama (Pure Node)
        if (count($uniqueLabels) === 1) {
            return reset($uniqueLabels);
        }

        // Jika tidak ada lagi atribut yang bisa dievaluasi
        if (empty($attributes)) {
            return $this->getMajorityLabel($data, $targetAttribute);
        }

        $baseEntropy = $this->calculateEntropy($data, $targetAttribute);
        $gains = [];

        // Hitung Gain untuk semua atribut yang tersisa
        foreach ($attributes as $attribute) {
            $gains[$attribute] = $this->calculateGain($data, $attribute, $targetAttribute, $baseEntropy);
        }

        // Pilih atribut dengan Information Gain Tertinggi sebagai Node/Akar
        arsort($gains);
        $bestAttribute = array_key_first($gains);

        $tree = [$bestAttribute => []];
        $attributeValues = array_unique(array_column($data, $bestAttribute));

        // Hapus atribut terpilih dari daftar untuk iterasi cabang berikutnya
        $remainingAttributes = array_values(array_diff($attributes, [$bestAttribute]));

        // Buat cabang untuk setiap nilai atribut terpilih
        foreach ($attributeValues as $value) {
            $subset = array_filter($data, fn($row) => $row[$bestAttribute] === $value);

            if (count($subset) === 0) {
                $tree[$bestAttribute][$value] = $this->getMajorityLabel($data, $targetAttribute);
            } else {
                // Rekursi untuk membangun cabang selanjutnya
                $tree[$bestAttribute][$value] = $this->buildTree($subset, $remainingAttributes, $targetAttribute);
            }
        }

        return $tree;
    }

    /**
     * Memprediksi data baru berdasarkan model Tree yang sudah dibuat.
     * Mengembalikan array berisi [Label Prediksi, Array Path/Alasan]
     */
    // public function predict(array $tree, array $item, array &$path = [])
    // {
    //     // Jika sudah mencapai Leaf Node (String)
    //     if (!is_array($tree)) {
    //         return ['label' => $tree, 'path' => $path];
    //     }

    //     // Ambil Node saat ini
    //     $attribute = array_key_first($tree);

    //     // Pastikan atribut ada di data item
    //     $value = $item[$attribute] ?? null;

    //     if ($value !== null && isset($tree[$attribute][$value])) {
    //         $path[] = str_replace('_', ' ', ucfirst($attribute)) . " is " . $value;
    //         return $this->predict($tree[$attribute][$value], $item, $path);
    //     }

    //     // Fallback jika data baru memiliki value yang tidak ada di data training
    //     return ['label' => 'Unknown', 'path' => $path];
    // }

    /**
     * Memprediksi data baru berdasarkan model Tree yang sudah dibuat.
     * Mengembalikan array berisi [Label Prediksi, Array Path/Alasan]
     */
    // [PERBAIKAN] Ubah "array $tree" menjadi "array|string $tree"
    public function predict(array|string $tree, array $item, array &$path = [])
    {
        // Jika sudah mencapai Leaf Node (String)
        if (!is_array($tree)) {
            return ['label' => $tree, 'path' => $path];
        }

        // Ambil Node saat ini
        $attribute = array_key_first($tree);

        // Pastikan atribut ada di data item
        $value = $item[$attribute] ?? null;

        if ($value !== null && isset($tree[$attribute][$value])) {
            $path[] = str_replace('_', ' ', ucfirst($attribute)) . " is " . $value;
            return $this->predict($tree[$attribute][$value], $item, $path);
        }

        // Fallback jika data baru memiliki value yang tidak ada di data training
        return ['label' => 'Unknown', 'path' => $path];
    }
}
