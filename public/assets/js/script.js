var wrapper = document.getElementById('wrapper');
var toggleButton = document.getElementById('menu-toggle');
if (toggleButton && wrapper) {
    toggleButton.addEventListener('click', function () {
        wrapper.classList.toggle('toggled');
        toggleButton.setAttribute('aria-expanded', String(!wrapper.classList.contains('toggled')));
    });
}

document.querySelectorAll('form[data-confirm-logout]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm('Are you sure you want to logout?')) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-progress]').forEach(function (progressBar) {
    const rawValue = Number.parseInt(progressBar.dataset.progress, 10);
    if (!Number.isFinite(rawValue)) {
        return;
    }

    const boundedValue = Math.min(100, Math.max(0, rawValue));
    progressBar.style.setProperty('--progress-width', boundedValue + '%');
});
