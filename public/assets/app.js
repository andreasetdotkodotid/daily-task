const filters = document.querySelectorAll('.filter');
const tasks = document.querySelectorAll('.task');
const form = document.querySelector('#taskForm');
const title = document.querySelector('#title');

filters.forEach((button) => {
    button.addEventListener('click', () => {
        const filter = button.dataset.filter;

        filters.forEach((item) => item.classList.remove('active'));
        button.classList.add('active');

        tasks.forEach((task) => {
            const visible = filter === 'all' || task.dataset.status === filter;
            task.classList.toggle('is-hidden', !visible);
        });
    });
});

form?.addEventListener('submit', (event) => {
    if (title.value.trim().length === 0) {
        event.preventDefault();
        title.focus();
    }
});

document.querySelectorAll('.delete-form').forEach((formElement) => {
    formElement.addEventListener('submit', (event) => {
        if (!window.confirm('Hapus task ini?')) {
            event.preventDefault();
        }
    });
});
