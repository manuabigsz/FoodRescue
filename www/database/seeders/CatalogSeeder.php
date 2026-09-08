<?php

namespace Database\Seeders;

use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Catálogo de referência do marketplace. Sem ele, um ambiente recém-migrado
 * deixa o produtor sem nenhum produto para escolher ao publicar um excedente.
 *
 * As classificações seguem a nomenclatura usada na comercialização hortifrúti,
 * incluindo a faixa fora de padrão comercial — que é justamente o excedente que
 * o FoodRescue existe para resgatar.
 */
class CatalogSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string}> */
    private const GRADES = [
        ['Extra', 'Sem defeitos aparentes, coloração e calibre uniformes.'],
        ['Tipo I', 'Defeitos leves que não comprometem aparência nem conservação.'],
        ['Tipo II', 'Defeitos visíveis de casca ou formato, próprio para consumo.'],
        ['Fora de padrão comercial', 'Calibre irregular ou aparência fora da exigência do varejo, íntegro e próprio para consumo.'],
    ];

    /** @var array<string, array<int, string>> */
    private const PRODUCTS = [
        'Hortaliças' => [
            'Abobrinha', 'Alface', 'Almeirão', 'Berinjela', 'Beterraba', 'Brócolis',
            'Cebola', 'Cenoura', 'Chuchu', 'Couve manteiga', 'Couve-flor', 'Pepino',
            'Pimentão', 'Quiabo', 'Repolho', 'Rúcula', 'Tomate', 'Vagem',
        ],
        'Frutas' => [
            'Abacate', 'Abacaxi', 'Banana-prata', 'Banana-nanica', 'Goiaba', 'Laranja',
            'Limão taiti', 'Mamão formosa', 'Manga', 'Maracujá', 'Melancia', 'Melão',
            'Morango', 'Tangerina', 'Uva',
        ],
        'Raízes e tubérculos' => [
            'Batata inglesa', 'Batata-doce', 'Inhame', 'Mandioca', 'Mandioquinha',
        ],
        'Grãos e cereais' => [
            'Arroz em casca', 'Feijão carioca', 'Feijão preto', 'Milho verde', 'Soja',
        ],
        'Outros' => [
            'Amendoim', 'Café em coco', 'Ovos caipira',
        ],
    ];

    public function run(): void
    {
        foreach (self::GRADES as $order => [$name, $description]) {
            QualityGrade::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $description, 'sort_order' => $order, 'active' => true],
            );
        }

        foreach (self::PRODUCTS as $products) {
            foreach ($products as $name) {
                AgriculturalProduct::query()->firstOrCreate(
                    ['slug' => Str::slug($name)],
                    ['name' => $name, 'active' => true],
                );
            }
        }
    }
}
