import { startStimulusApp } from '@symfony/stimulus-bundle';

/**
 * Arranca Stimulus.
 *
 * No hace falta importar ni registrar nada a mano: StimulusBundle descubre
 * solo todo `assets/controllers/*_controller.js` y deriva el nombre del
 * fichero (`amount_type_controller.js` -> `amount-type`).
 *
 * Los controladores marcados con `stimulusFetch: 'lazy'` sobre la clase no se
 * descargan hasta que aparece su `data-controller` en el DOM. Se dejan en
 * carga normal los que interceptan un envío o bloquean una acción destructiva
 * (confirm, confirm-match, friendship), donde llegar tarde significaría dejar
 * pasar el submit sin su comprobación.
 */
startStimulusApp();
