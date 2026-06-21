/* IT Pricing view — internet + access-control rate cards with full breakdown */
(() => {
  const GST_PCT = 18, CONV_PCT = 30, ONE_TIME = 5000;

  const TATA = [
    ['50 Mbps', 165000], ['100 Mbps', 265000], ['155 Mbps', 400000], ['200 Mbps', 500000],
    ['300 Mbps', 600000], ['500 Mbps', 750000], ['1000 Mbps', 1100000],
  ];
  const ACT = [
    ['10 Mbps', 80000], ['20 Mbps', 90000], ['30 Mbps', 120000], ['50 Mbps', 150000],
    ['100 Mbps', 250000], ['200 Mbps', 400000], ['300 Mbps', 550000], ['500 Mbps', 720000], ['1 Gbps', 1000000],
  ];
  const ACCESS = [
    ['Single door', 46600], ['Double door', 68650],
  ];

  const inr = n => '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 });

  function row(label, vendor) {
    const gst = vendor * GST_PCT / 100;
    const conv = vendor * CONV_PCT / 100;
    const total = vendor + gst + conv;
    return `<tr>
      <td><b>${App.esc(label)}</b></td>
      <td class="num">${inr(vendor)}</td>
      <td class="num muted">+ ${inr(gst)}</td>
      <td class="num muted">+ ${inr(conv)}</td>
      <td class="num"><b style="color:var(--ac)">${inr(total)}</b></td>
    </tr>`;
  }

  function card(title, sub, rows) {
    return `<div class="panel" style="margin-bottom:18px">
      <div class="panel-pad" style="padding-bottom:6px"><div class="card-title">${App.esc(title)}${sub ? `<span class="pill">${App.esc(sub)}</span>` : ''}</div></div>
      <div class="tablewrap"><table class="ratecard">
        <thead><tr>
          <th>Plan</th>
          <th class="num">Vendor</th>
          <th class="num">GST ${GST_PCT}%</th>
          <th class="num">Convenience ${CONV_PCT}%</th>
          <th class="num">Total</th>
        </tr></thead>
        <tbody>${rows.map(([l, v]) => row(l, v)).join('')}</tbody>
      </table></div>
    </div>`;
  }

  function load() {
    const box = document.getElementById('pricingContent');
    if (!box) return;
    box.innerHTML =
      `<p class="tiny muted" style="margin:-6px 0 18px">Total = Vendor price + ${GST_PCT}% GST + ${CONV_PCT}% MOSS convenience (each computed on the vendor price). Only ACT internet plans carry a one-time installation charge of <b>${inr(ONE_TIME)}</b>.</p>`
      + card('Internet — TATA', '', TATA)
      + card('Internet — ACT', `one-time install ${inr(ONE_TIME)}`, ACT)
      + card('Access Control — Swing door', 'per door', ACCESS);
  }

  App.register('pricing', load);
})();
