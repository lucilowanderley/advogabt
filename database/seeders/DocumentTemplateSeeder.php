<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Procuração ad judicia et extra',
                'category' => 'procuracao',
                'content' => <<<'TXT'
PROCURAÇÃO AD JUDICIA ET EXTRA

OUTORGANTE: {{cliente.nome}}, {{cliente.nacionalidade}}, {{cliente.estado_civil}}, {{cliente.profissao}}, inscrito(a) no CPF/CNPJ sob o nº {{cliente.documento}}, RG nº {{cliente.rg}}, residente e domiciliado(a) em {{cliente.endereco}}.

OUTORGADO: {{advogado.nome}}, advogado(a) inscrito(a) na OAB sob o nº {{advogado.oab}}, integrante do escritório {{escritorio.nome}}.

PODERES: Pelo presente instrumento particular de mandato, o(a) OUTORGANTE nomeia e constitui seu bastante procurador o OUTORGADO, a quem confere amplos poderes para o foro em geral, com a cláusula ad judicia et extra, em qualquer Juízo, Instância ou Tribunal, podendo propor contra quem de direito as ações competentes e defendê-lo(a) nas contrárias, seguindo umas e outras até final decisão, usando os recursos legais e acompanhando-os, conferindo-lhe ainda poderes especiais para confessar, desistir, transigir, firmar compromissos ou acordos, receber e dar quitação, agindo em conjunto ou separadamente, podendo ainda substabelecer esta em outrem, com ou sem reservas de iguais poderes.

{{cliente.endereco}}, {{data.extenso}}.



_____________________________________
{{cliente.nome}}
TXT,
            ],
            [
                'name' => 'Contrato de honorários advocatícios',
                'category' => 'contrato',
                'content' => <<<'TXT'
CONTRATO DE PRESTAÇÃO DE SERVIÇOS ADVOCATÍCIOS

CONTRATANTE: {{cliente.nome}}, inscrito(a) no CPF/CNPJ sob o nº {{cliente.documento}}, residente e domiciliado(a) em {{cliente.endereco}}, e-mail {{cliente.email}}, telefone {{cliente.telefone}}.

CONTRATADO: {{escritorio.nome}}, neste ato representado por {{advogado.nome}}, OAB nº {{advogado.oab}}.

CLÁUSULA 1ª – DO OBJETO
O presente contrato tem por objeto a prestação de serviços advocatícios pelo CONTRATADO ao CONTRATANTE, referentes a: {{processo.titulo}} (processo nº {{processo.numero}}), em face de {{processo.parte_contraria}}.

CLÁUSULA 2ª – DOS HONORÁRIOS
Pelos serviços ora contratados, o CONTRATANTE pagará ao CONTRATADO o valor de R$ ________ (________________), na forma ______________, além de ____% (________ por cento) sobre o proveito econômico obtido ao final da demanda.

CLÁUSULA 3ª – DAS DESPESAS
Custas processuais, emolumentos, diligências e demais despesas necessárias ao andamento do feito correrão por conta do CONTRATANTE, mediante prévia comunicação.

CLÁUSULA 4ª – DA RESCISÃO
O presente contrato poderá ser rescindido por qualquer das partes mediante notificação por escrito, sendo devidos os honorários proporcionais aos serviços já prestados.

CLÁUSULA 5ª – DO FORO
Fica eleito o foro da comarca de {{processo.comarca}} para dirimir quaisquer dúvidas oriundas deste contrato.

E por estarem justos e contratados, assinam o presente em duas vias de igual teor.

{{data.extenso}}.


_____________________________________
{{cliente.nome}} – CONTRATANTE


_____________________________________
{{advogado.nome}} – OAB {{advogado.oab}}
TXT,
            ],
            [
                'name' => 'Declaração de hipossuficiência',
                'category' => 'declaracao',
                'content' => <<<'TXT'
DECLARAÇÃO DE HIPOSSUFICIÊNCIA ECONÔMICA

Eu, {{cliente.nome}}, {{cliente.nacionalidade}}, {{cliente.estado_civil}}, {{cliente.profissao}}, inscrito(a) no CPF sob o nº {{cliente.documento}}, residente e domiciliado(a) em {{cliente.endereco}}, DECLARO, para os fins do art. 98 e seguintes do Código de Processo Civil, que não possuo condições de arcar com as custas processuais e honorários advocatícios sem prejuízo do meu próprio sustento e de minha família, razão pela qual requeiro os benefícios da GRATUIDADE DA JUSTIÇA.

Declaro, ainda, estar ciente de que a falsidade desta declaração sujeita o declarante às sanções civis, administrativas e criminais previstas na legislação.

{{data.extenso}}.


_____________________________________
{{cliente.nome}}
TXT,
            ],
            [
                'name' => 'Petição simples – juntada de documentos',
                'category' => 'peticao',
                'content' => <<<'TXT'
EXCELENTÍSSIMO(A) SENHOR(A) DOUTOR(A) JUIZ(A) DE DIREITO DA {{processo.vara}} DA COMARCA DE {{processo.comarca}}

Processo nº {{processo.numero}}

{{cliente.nome}}, já qualificado(a) nos autos da ação em epígrafe, que move em face de {{processo.parte_contraria}}, vem, respeitosamente, à presença de Vossa Excelência, por seu advogado que esta subscreve, requerer a JUNTADA dos documentos anexos, para que produzam seus efeitos legais.

Termos em que,
pede deferimento.

{{data.extenso}}.


_____________________________________
{{advogado.nome}}
OAB {{advogado.oab}}
TXT,
            ],
        ];

        foreach ($templates as $t) {
            DocumentTemplate::firstOrCreate(['name' => $t['name']], $t);
        }
    }
}
