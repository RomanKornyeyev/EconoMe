import './stimulus_bootstrap.js';
import './styles/app.css';

// sustituye las validaciones nativas de HTML5 por las de Bootstrap
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form.needs-validation').forEach(form => {
        form.setAttribute('novalidate', '');
        form.addEventListener('submit', e => {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
});
