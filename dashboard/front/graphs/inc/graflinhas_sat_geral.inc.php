<?php
/**
 * graflinhas_sat_geral.inc.php  (OTIMIZADO)
 * --------------------------------------------------------------
 * ANTES: para montar as séries "Atrasados", "Solucionados" e
 * "Fechados" o código executava UMA query por mês, dentro de
 * laços while (problema clássico N+1). Com 2 anos de histórico
 * eram ~70 idas ao banco só para este gráfico.
 *
 * AGORA: cada série é obtida com UMA ÚNICA query agregada
 * (GROUP BY mês). O resultado é idêntico, mas o número de
 * consultas cai de (3*N + 2) para 5, independente do histórico.
 *
 * As variáveis de saída foram mantidas EXATAMENTE iguais
 * ($grfm3, $quantm2, $quants2, $quanta2, $quantf2, $quantsat2,
 *  $opened, $solved, $late, $closed, $satisf, $arr_month, ...)
 * para que o bloco de geração do gráfico continue funcionando
 * sem nenhuma alteração.
 */

/* ---------------------------------------------------------------
 * 1) Lista-mestre de meses + chamados abertos (opened)
 * ------------------------------------------------------------- */
$querym = "
SELECT DATE_FORMAT( date, '%b-%y' ) AS month_l,
       DATE_FORMAT( date, '%y-%m' ) AS month,
       COUNT( id ) AS nb
FROM glpi_tickets
WHERE glpi_tickets.is_deleted = '0'
".$entidade."
GROUP BY month
ORDER BY month ASC ";

$resultm = $DB->query($querym) or die('erro');

$arr_grfm  = array(); // abertos por mês
$arr_month = array(); // mesma lista de meses, zerada (alinhamento)
while ($row_result = $DB->fetchAssoc($resultm)) {
    $mes_label             = $row_result['month_l'];
    $arr_grfm[$mes_label]  = $row_result['nb'];
    $arr_month[$mes_label] = 0;
}

$grfm    = array_keys($arr_grfm);
$quantm  = array_values($arr_grfm);
$grfm3   = json_encode($grfm);
$quantm2 = implode(',', $quantm);
$opened  = array_sum($quantm);

/* Helper: executa uma query agregada (month_l => nb) e devolve um
 * array alinhado à lista-mestre de meses (zeros onde não houver
 * dado), preservando a ordem cronológica. */
$alinhar_por_mes = function ($sql) use ($DB, $arr_month) {
    $res = $DB->query($sql) or die('erro_agg');
    $mapa = array();
    while ($r = $DB->fetchAssoc($res)) {
        $mapa[$r['month_l']] = $r['nb'];
    }
    // array_merge mantém a ordem de $arr_month (cronológica)
    return array_merge($arr_month, $mapa);
};

/* ---------------------------------------------------------------
 * 2) Atrasados (late) - 1 query agregada
 * ------------------------------------------------------------- */
$arr_open = $alinhar_por_mes("
SELECT DATE_FORMAT( date, '%b-%y' ) AS month_l,
       DATE_FORMAT( date, '%y-%m' ) AS month,
       COUNT( id ) AS nb
FROM glpi_tickets
WHERE solvedate IS NOT NULL
  AND time_to_resolve IS NOT NULL
  AND solvedate > time_to_resolve
  ".$entidade."
GROUP BY month
ORDER BY month ASC ");

$grfa    = array_keys($arr_open);
$quanta  = array_values($arr_open);
$grfa3   = json_encode($grfa);
$quanta2 = implode(',', $quanta);
$late    = array_sum($quanta);

/* ---------------------------------------------------------------
 * 3) Solucionados (solved) - 1 query agregada
 * ------------------------------------------------------------- */
$arr_grfs = $alinhar_por_mes("
SELECT DATE_FORMAT( date, '%b-%y' ) AS month_l,
       DATE_FORMAT( date, '%y-%m' ) AS month,
       COUNT( id ) AS nb
FROM glpi_tickets
WHERE glpi_tickets.is_deleted = '0'
  AND glpi_tickets.solvedate IS NOT NULL
  ".$entidade."
GROUP BY month
ORDER BY month ASC ");

$grfs    = array_keys($arr_grfs);
$quants  = array_values($arr_grfs);
$grfs3   = json_encode($grfs);
$quants2 = implode(',', $quants);
$solved  = array_sum($quants);

/* ---------------------------------------------------------------
 * 4) Fechados (closed) - 1 query agregada
 * ------------------------------------------------------------- */
$arr_grff = $alinhar_por_mes("
SELECT DATE_FORMAT( date, '%b-%y' ) AS month_l,
       DATE_FORMAT( date, '%y-%m' ) AS month,
       COUNT( id ) AS nb
FROM glpi_tickets
WHERE glpi_tickets.is_deleted = '0'
  AND glpi_tickets.closedate IS NOT NULL
  ".$entidade."
GROUP BY month
ORDER BY month ASC ");

$grff    = array_keys($arr_grff);
$quantf  = array_values($arr_grff);
$grff3   = json_encode($grff);
$quantf2 = implode(',', $quantf);
$closed  = array_sum($quantf);

/* ---------------------------------------------------------------
 * 5) Satisfação (%) - já era 1 query agregada
 * ------------------------------------------------------------- */
$query_sat = "
SELECT DATE_FORMAT( glpi_tickets.date, '%b-%y' ) AS month_l,
       DATE_FORMAT( glpi_tickets.date, '%y-%m' ) AS month,
       AVG( glpi_ticketsatisfactions.satisfaction ) AS media
FROM glpi_tickets
JOIN glpi_ticketsatisfactions
  ON glpi_ticketsatisfactions.tickets_id = glpi_tickets.id
WHERE glpi_tickets.is_deleted = '0'
".$entidade."
GROUP BY month
ORDER BY month ASC ";

$result_sat = $DB->query($query_sat) or die('erro');

$arr_grfsat = array();
while ($row_result1 = $DB->fetchAssoc($result_sat)) {
    $mes_label              = $row_result1['month_l'];
    $arr_grfsat[$mes_label] = round(($row_result1['media'] / 5) * 100, 1);
}

$arr_sat    = array_merge($arr_month, $arr_grfsat);
$grfsat     = array_keys($arr_sat);
$quantsat   = array_values($arr_sat);
$grfsat3    = json_encode($grfsat);
$quantsat2  = implode(',', $quantsat);
$satisf     = round(array_sum($quantsat), 0);


echo "
<script type='text/javascript'>
$(function () {

        $('#graf_linhas').highcharts({
            chart: {";

if(array_sum($quantsat) != 0) {
	echo        "type: 'line', \n";
}
else {
	echo        "type: 'areaspline',";
}

echo           "height: 460

            },
            title: {
                text: '".__('Tickets','dashboard')."'
            },
            legend: {
                layout: 'horizontal',
                align: 'center',
                verticalAlign: 'bottom',
                x: 0,
                y: 0,
                //floating: true,
                borderWidth: 0,
                //backgroundColor: '#FFFFFF',
                adjustChartSize: true
            },
            xAxis: {
                categories: $grfm3,
                labels: {
                    rotation: -55,
                    align: 'right',
                    style: {
                        //fontSize: '11px',
                        //fontFamily: 'Verdana, sans-serif'
                    }
                }
            },
          		 ";

if(array_sum($quantsat) != 0) {

     echo  "
            yAxis: [{
	 						minPadding: 0,
   	 					maxPadding: 0,
    						min: 0,
    						//max:1,
   						showLastLabel:false,
    						//tickInterval:1,

                title: { // Primary yAxis
                    text: '".__('Tickets','dashboard')."'
                }
             },

          		{ // Secondary yAxis
                title: {
                    text: '".__('Satisfaction','dashboard')."',
                    style: {
                       // color: '#4572A7'
                    }
                },
                labels: {
                    format: '{value} %',
                    style: {
                       // color: '#4572A7'
                    }
                },
                opposite: true
            }],
            ";

         }

else {

echo "      yAxis: {
	 						minPadding: 0,
   	 					maxPadding: 0,
    						min: 0,
    						//max:1,
   						showLastLabel:false,
    						//tickInterval:1,

                title: { // Primary yAxis
                    text: '".__('Tickets','dashboard')."'
                }
             },  ";
      }

         echo  "plotOptions: {
                column: {
                    pointPadding: 0.2,
  		              borderWidth: 2,
      	           borderColor: 'white',
         	        shadow:true,
                	  showInLegend: true
                },
                areaspline: {
                    fillOpacity: 0.5
                }
                },

            tooltip: {
                shared: true
            },
            credits: {
                enabled: false
            },

          series: [";

if(array_sum($quantsat) != 0) {

          echo  "
					{ // satisfacao
                name: '".__('Satisfaction','dashboard')." (".$satisf.")',

                type: 'column',
                yAxis: 1,

          		data: [$quantsat2],
                tooltip: {
                    valueSuffix: ' %'
                },
                    dataLabels: {
                    enabled: true,
                    //color: '#000099',
                    align: 'center',
                    x: 1,
                    y: 1,
                    format: '{y} %',
                    style: {
                        //fontSize: '11px',
                        //fontFamily: 'Verdana, sans-serif'
                    },
                    formatter: function () {
                    return Highcharts.numberFormat(this.y, 0, '','');
                }
                },

                },";
            }

echo "
          		 {
                name: '".__('Opened','dashboard')." (".$opened.")',

                 dataLabels: {
                    enabled: true,
                    //color: '#000000',
                    style: {
                        //fontSize: '11px',
                        //fontFamily: 'Verdana, sans-serif',
                        //fontWeight: 'bold'
                    }
                    },
                data: [$quantm2]
                },
                {
                name: '".__('Solved','dashboard')." (".$solved.")',
                dataLabels: {
                    enabled: false,
                    //color: '#000',
                    style: {
                        //fontSize: '11px',
                        //fontFamily: 'Verdana, sans-serif',
                        //fontWeight: 'bold'
                    },
                    },
                data: [$quants2]
                },

                {
                name: '".__('Late','dashboard')." (".$late.")',

                dataLabels: {
                    enabled: true,
                    //color: '#800000',
                    style: {
                        //fontSize: '11px',
                        //fontFamily: 'Verdana, sans-serif',
                        //fontWeight: 'bold'
                    },
                    },
                	data: [$quanta2]
                },
					 {
                	name: '".__('Closed','dashboard')." (".$closed.")',
                	data: [$quantf2]
                },
                ]
        });
    });
  </script>
";

		?>
