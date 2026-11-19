@extends('layouts.app')
@section('title','Analytics')
@section('page-title', auth()->user()->isEvaluator() ? 'My Assignment Analytics' : 'Proposal Analytics')
@section('content')
<div class="page-intro">
	<div>
		<h1>{{ auth()->user()->isEvaluator() ? 'My assigned proposal analytics' : 'Regional performance analytics' }}</h1>
	</div>
</div>

<form class="filter-bar compact">
	<label>Year
		<select name="year">
			@forelse($years as $y)
				<option {{ $year==$y?'selected':'' }}>{{ $y }}</option>
			@empty
				<option>{{ now()->year }}</option>
			@endforelse
		</select>
	</label>
	<label>Quarter
		<select name="quarter">
			<option value="0">Annual</option>
			@for($q=1;$q<=4;$q++)
				<option value="{{ $q }}" {{ $quarter==$q?'selected':'' }}>Q{{ $q }}</option>
			@endfor
		</select>
	</label>
	
        @include('partials.date-range-filter', ['dateBasis' => 'Submission date', 'overridesYear' => true])
        <button class="btn primary">Apply</button>
</form>

@php
	$total=array_sum(array_column($rows,'count'));
	$funded=array_sum(array_column($rows,'funded_count'));
	$amount=array_sum(array_column($rows,'funded_amount'));
@endphp

<div class="metric-grid three">
	<article class="metric">
		<span class="metric-icon blue">▤</span>
		<div>
			<small>Total proposals</small>
			<strong>{{ $total }}</strong>
			<em>{{ $quarter?'Quarter '.$quarter:'Full year' }} {{ $range->label($year) }}</em>
		</div>
	</article>
	<article class="metric">
		<span class="metric-icon">✓</span>
		<div>
			<small>Funded proposals</small>
			<strong>{{ $funded }}</strong>
			<em>{{ $total?number_format($funded/$total*100,1):0 }}% funding rate</em>
		</div>
	</article>
	<article class="metric">
		<span class="metric-icon dark">₱</span>
		<div>
			<small>Total funded</small>
			<strong class="money">₱{{ number_format($amount,0) }}</strong>
			<em>recorded proposal value</em>
		</div>
	</article>
</div>

<div class="grid two" style="gap:1rem;margin-top:1rem;">
	<section class="panel">
		<div class="panel-head"><div><span class="eyebrow">VISUALS</span><h2>Proposals by province</h2></div></div>
		<div class="chart-controls" style="display:flex;justify-content:flex-end;margin:0.5rem 0;">
			<button id="exportProvince" class="btn">Export PNG</button>
		</div>
		<div class="chart-wrap" style="min-height:320px;"><canvas id="proposalsProvinceChart" style="width:100%;height:100%;"></canvas></div>
	</section>
	<section class="panel">
		<div class="panel-head"><div><span class="eyebrow">VISUALS</span><h2>Funded amount by sector</h2></div></div>
		<div class="chart-controls" style="display:flex;justify-content:flex-end;margin:0.5rem 0;">
			<button id="exportSector" class="btn">Export PNG</button>
		</div>
		<div class="chart-wrap" style="min-height:320px;"><canvas id="fundedSectorChart" style="width:100%;height:100%;"></canvas></div>
	</section>
</div>

<section class="panel">
	<div class="panel-head">
		<div><span class="eyebrow">PROPOSAL MATRIX</span><h2>Province × program</h2></div>
	</div>
	<div class="table-wrap">
		<table>
			<thead>
				<tr>
					<th>Province</th>
					<th>Program</th>
					<th class="num">Proposals</th>
					<th class="num">Funded</th>
					<th class="num">Funding rate</th>
					<th class="num">Funded amount</th>
				</tr>
			</thead>
			<tbody>
				@php $lastProvince = null; @endphp
				@foreach($rows as $r)
					@php $showProvince = $r['province'] !== $lastProvince; @endphp
					<tr>
						@if($showProvince)
							<td><b>{{ $r['province'] }}</b></td>
							@php $lastProvince = $r['province']; @endphp
						@else
							<td></td>
						@endif
						<td><span class="sector-badge {{ strtolower($r['sector']) }}">{{ $r['sector'] }}</span></td>
						<td class="num">{{ $r['count'] }}</td>
						<td class="num">{{ $r['funded_count'] }}</td>
						<td class="num">{{ $r['count']?number_format($r['funded_count']/$r['count']*100,1):0 }}%</td>
						<td class="num">₱{{ number_format($r['funded_amount'],2) }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script>
(function(){
	const provinceLabels = {!! json_encode($provinceLabels) !!};
	const provinceCounts = {!! json_encode($provinceCounts) !!};
	const sectorLabels = {!! json_encode($sectorLabels) !!};
	const sectorFunded = {!! json_encode($sectorFundedAmounts) !!};

	const defaultColors = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7'];

	// Register datalabels plugin
	Chart.register(ChartDataLabels);

	// Proposals by province (bar)
	const pctx = document.getElementById('proposalsProvinceChart');
	let provinceChart = null;
	if(pctx){
		provinceChart = new Chart(pctx,{
			type:'bar',
			data:{
				labels:provinceLabels,
				datasets:[{
					label:'Proposals',
					data:provinceCounts,
					backgroundColor: defaultColors.slice(0, provinceLabels.length),
					borderRadius: 6
				}]
			},
			options:{
				responsive:true,
				maintainAspectRatio:false,
				plugins:{
					legend:{display:false},
					datalabels:{
						anchor:'end',
						align:'end',
						formatter: (v) => v>0?v:'',
						font:{weight:'600',size:11}
					},
					tooltip:{
						callbacks:{
							label: function(ctx){
								return ctx.dataset.label + ': ' + (ctx.raw?ctx.raw.toLocaleString():0);
							}
						}
					}
				},
				scales:{
					x:{ticks:{maxRotation:0}},
					y:{beginAtZero:true, ticks:{callback: v => v.toLocaleString()}}
				}
			}
		});
	}

	// Funded amount by sector (doughnut)
	const fctx = document.getElementById('fundedSectorChart');
	let sectorChart = null;
	if(fctx){
		sectorChart = new Chart(fctx,{
			type:'doughnut',
			data:{
				labels:sectorLabels,
				datasets:[{
					label:'Funded amount',
					data:sectorFunded,
					backgroundColor: defaultColors.slice(0, sectorLabels.length)
				}]
			},
			options:{
				responsive:true,
				maintainAspectRatio:false,
				plugins:{
					legend:{position:'bottom'},
					datalabels:{
						formatter: (value, ctx) => {
							const sum = ctx.dataset.data.reduce((a,b)=>a+(b||0),0);
							const pct = sum?((value/sum)*100).toFixed(1)+'%':'';
							return pct;
						},
						color:'#fff',
						font:{weight:'600',size:11}
					},
					tooltip:{
						callbacks:{
							label: function(ctx){
								const v = ctx.raw || 0;
								return ctx.label + ': ₱' + Number(v).toLocaleString();
							}
						}
					}
				}
			}
		});
	}

	// Export buttons
	const exportProvince = document.getElementById('exportProvince');
	if(exportProvince && provinceChart){
		exportProvince.addEventListener('click', ()=>{
			const a = document.createElement('a');
			a.href = provinceChart.toBase64Image();
			a.download = `proposals-by-province-${new Date().toISOString().slice(0,10)}.png`;
			a.click();
		});
	}
	const exportSector = document.getElementById('exportSector');
	if(exportSector && sectorChart){
		exportSector.addEventListener('click', ()=>{
			const a = document.createElement('a');
			a.href = sectorChart.toBase64Image();
			a.download = `funded-by-sector-${new Date().toISOString().slice(0,10)}.png`;
			a.click();
		});
	}

})();
</script>

@endsection
