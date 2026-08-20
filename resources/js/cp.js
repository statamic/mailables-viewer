import Mailables from './pages/Mailables.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('mailables-viewer::Mailables', Mailables);
});
