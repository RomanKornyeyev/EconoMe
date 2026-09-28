import { Controller } from '@hotwired/stimulus';

/**
 * Gráfico de reparto por categoría (components/_chart_doughnut.html.twig).
 *
 * Se monta sobre la tarjeta del gráfico y se encarga de:
 *
 *  - Construir el Chart.js con los sectores que llegan en el *-value.
 *  - Navegar al listado al pinchar un sector, con confirmación en táctil (el
 *    primer toque solo abre el tooltip).
 *  - Redimensionar cuando se muestra la pestaña que lo contiene: Chart.js mide
 *    0 px dentro de un .tab-pane oculto, así que un gráfico que nace escondido
 *    sale aplastado si no se le avisa. Si no hay pestañas, el evento no llega
 *    y no pasa nada.
 *
 * Chart.js llega como global desde el CDN (partials/assets/_chartjs.html.twig).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
  static targets = ['canvas'];

  static values = {
    // { labels: [], values: [], colors: [], links: [] }
    categories: Object,
  };

  connect() {
    if (typeof Chart === 'undefined' || !this.hasCanvasTarget) return;

    this.#build();

    this.onTabShown = () => {
      if (this.canvasTarget.offsetParent !== null) this.chart?.resize();
    };
    document.addEventListener('shown.bs.tab', this.onTabShown);
  }

  disconnect() {
    document.removeEventListener('shown.bs.tab', this.onTabShown);
    this.chart?.destroy();
  }

  #build() {
    const canvas = this.canvasTarget;
    const { labels, values, colors, links } = this.categoriesValue;

    // Sector cuyo tooltip ya estaba abierto justo antes del toque actual.
    // En táctil hace de confirmación: el primer toque solo abre el tooltip y el
    // segundo, sobre el mismo sector, navega. Así un toque accidental no saca
    // al usuario del dashboard. Con ratón se navega al primer clic.
    let armedIndex = null;
    let pointerType = 'mouse';

    const goToCategory = (index) => {
      if (links[index]) {
        window.location.href = links[index];
      }
    };

    this.chart = new Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }],
      },
      options: {
        responsive: true,
        // El alto lo fija el contenedor .dash-chart.
        maintainAspectRatio: false,
        // Ver chart_bar_controller: al doble de densidad, el remuestreo por
        // caer en fracciones de píxel deja de verse en los rótulos.
        devicePixelRatio: Math.max(2, window.devicePixelRatio || 1),
        cutout: '65%',
        onHover: (event, elements) => {
          canvas.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;

          const index = elements[0].index;
          if (pointerType === 'touch' && armedIndex !== index) {
            return; // primer toque: Chart.js ya ha abierto el tooltip
          }
          goToCategory(index);
        },
        plugins: {
          // Sin leyenda dentro del lienzo: debajo del donut va la lista de
          // categorías en HTML, que además de color y nombre da porcentaje e
          // importe, se lee mejor y se pulsa mejor que un rótulo pintado.
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (context) => {
                const value = parseFloat(context.parsed) || 0;
                const total = context.chart.data.datasets[0].data
                  .reduce((acc, v) => acc + (parseFloat(v) || 0), 0);
                const porcentaje = total > 0 ? (value / total) * 100 : 0;

                const importe = value.toLocaleString('es-ES', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                });
                const pct = porcentaje.toLocaleString('es-ES', {
                  minimumFractionDigits: 1,
                  maximumFractionDigits: 1,
                });

                return ` ${importe} € (${pct}%)`;
              },
            },
          },
        },
      },
    });

    // pointerdown se dispara antes de que Chart.js procese el toque, así que
    // aquí el tooltip todavía refleja el estado *previo*: si ya estaba abierto
    // sobre este sector, el toque que viene es el segundo y puede navegar.
    canvas.addEventListener('pointerdown', (event) => {
      pointerType = event.pointerType || 'mouse';
      const active = this.chart.tooltip ? this.chart.tooltip.getActiveElements() : [];
      armedIndex = active.length ? active[0].index : null;
    }, { passive: true });
  }
}
