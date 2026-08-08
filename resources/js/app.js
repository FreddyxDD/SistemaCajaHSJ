import 'preline';

// Preline inicializa sus componentes al cargar el DOM. Livewire navega sin recargar
// la pagina (wire:navigate), por lo que hay que re-inicializar en cada navegacion y
// tras cada actualizacion de un componente, o los overlays/dropdowns dejan de
// responder al reemplazarse el HTML.
const initializePreline = () => {
    window.HSStaticMethods?.autoInit();
};

document.addEventListener('DOMContentLoaded', initializePreline);
document.addEventListener('livewire:navigated', initializePreline);
document.addEventListener('livewire:initialized', () => {
    Livewire.hook('morphed', initializePreline);
});
