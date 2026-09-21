import { Controller } from '@hotwired/stimulus';

/**
 * Gráfico de barras de ingresos vs gastos (components/_chart_bar.html.twig).
 *
 * Se monta sobre la tarjeta del gráfico y se encarga de:
 *
 *  - Construir el Chart.js con la serie de 12 meses que llega en los *-value.
 *  - Recortarla a los 6 meses del período o dejarla entera. Por defecto, 6
 *    meses en móvil y año en escritorio; a partir de ahí manda lo que elija el
 *    usuario.
 *  - Redimensionar cuando se muestra la pestaña que lo contiene: Chart.js mide
 *    0 px dentro de un .tab-pane oculto, así que un gráfico que nace escondido
 *    sale aplastado si no se le avisa. Si no hay pestañas, el evento no llega
 *    y no pasa nada.
 *
 * Chart.js llega como global desde el CDN (partials/assets/_chartjs.html.twig).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['canvas', 'rangeButton'];

  static values = {
    // { labels: [...12], income: [...12], expense: [...12] }
    monthly: Object,
    // [mesInicial, mesFinal] 1-based de la vista corta
    window: Array,
  };

  connect() {
    if (typeof Chart === 'undefined' || !this.hasCanvasTarget) return;

    this.range = window.matchMedia('(min-width: 992px)').matches ? 'year' : 'window';

    this.#build();
    this.#syncRangeButtons();

    this.onTabShown = () => {
      if (this.canvasTarget.offsetParent !== null) this.chart?.resize();
    };
    document.addEventListener('shown.bs.tab', this.onTabShown);
  }

  disconnect() {
    document.removeEventListener('shown.bs.tab', this.onTabShown);
    this.chart?.destroy();
  }

  /** Cambia entre los 6 meses del período y el año entero. */
  setRange(event) {
    const range = event.currentTarget.dataset.range;
    if (!this.chart || range === this.range) return;

    this.range = range;

    const { labels, income, expense } = this.#slicedSeries();
    this.chart.data.labels = labels;
    this.chart.data.datasets[0].data = income;
    this.chart.data.datasets[1].data = expense;
    this.chart.update();

    this.#syncRangeButtons();
  }

  /** Serie completa o recortada a la ventana del período, según el rango. */
  #slicedSeries() {
    const { labels, income, expense } = this.monthlyValue;

    if (this.range === 'year') {
      return { labels, income, expense };
    }

    const [from, to] = this.windowValue;
    return {
      labels: labels.slice(from - 1, to),
      income: income.slice(from - 1, to),
      expense: expense.slice(from - 1, to),
    };
  }

  #syncRangeButtons() {
    this.rangeButtonTargets.forEach((button) => {
      const active = button.dataset.range === this.range;
      button.classList.toggle('btn-secondary', active);
      button.classList.toggle('btn-outline-secondary', !active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  #build() {
    const { labels, income, expense } = this.#slicedSeries();

    this.chart = new Chart(this.canvasTarget.getContext('2d'), {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Ingresos',
            data: income,
            backgroundColor: 'rgba(75, 192, 130, 0.8)',
            borderRadius: 4,
            barPercentage: 0.6,
          },
          {
            label: 'Gastos',
            data: expense,
            backgroundColor: 'rgba(240, 149, 149, 0.8)',
            borderRadius: 4,
            barPercentage: 0.6,
          },
        ],
      },
      options: {
        responsive: true,
        // El alto lo fija el contenedor .dash-chart: proporción fija en
        // escritorio, altura fija en móvil.
        maintainAspectRatio: false,
        // El canvas es un mapa de bits: si su caja cae en una fracción de
        // píxel —y cae, porque la cabecera de la página ya arranca en .781— el
        // navegador lo reescala entero y sus rótulos salen borrosos. Pintando
        // al doble de densidad el remuestreo deja de notarse. Son 4 veces más
        // píxeles de un gráfico pequeño: irrelevante.
        devicePixelRatio: Math.max(2, window.devicePixelRatio || 1),
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, padding: 16, font: { size: 12 } } },
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 12 } } },
          y: {
            grid: { color: 'rgba(0,0,0,0.05)' },
            ticks: { callback: (v) => (v >= 1000 ? v / 1000 + 'k' : v), font: { size: 12 } },
            border: { display: false },
          },
        },
      },
    });
  }
}
