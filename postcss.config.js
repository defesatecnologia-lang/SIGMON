import autoprefixer from 'autoprefixer';
import cssnano from 'cssnano';
import purgecss from '@fullhuman/postcss-purgecss';

export default {
  plugins: [
    autoprefixer(),
    ...(process.env.NODE_ENV === 'production' ? [
      purgecss({
        content: [
          './src/**/*.html',
          './src/**/*.ts',
          './src/**/*.php',
          './resources/**/*.php',
          './auth/**/*.php',
          './accounts/**/*.php',
          './inc/**/*.php',
          './api/**/*.php',
          './config/**/*.php',
          './public/**/*.php',
          './*.php',
        ],
        defaultExtractor: content => content.match(/[\w-/:]+(?<!:)/g) || [],
        safelist: {
          standard: [
            'show', 'collapsing', 'collapsed', 'modal-backdrop',
            'tooltip', 'popover', 'dropdown-menu', 'accordion',
            'carousel', 'carousel-item', 'fade', 'd-none',
            'leaflet-container', 'leaflet-popup',
            /^bs-/, /^navbar-/, /^nav-/, /^btn-/,
            /^alert-/, /^badge-/, /^card-/, /^form-/,
            /^table-/, /^text-/, /^bg-/, /^border-/,
            /^offset-/, /^col-/, /^row/, /^g-/,
            // `body class="page-<pagina>"` é montado dinamicamente no PHP
            // (inc/header.php), então o PurgeCSS não encontra a classe no
            // conteúdo e apagaria qualquer regra escopada por `.page-*`.
            /^page-/,
            // Ícones do Font Awesome: classes usadas em templates JS,
            // montadas dinamicamente ou por nome vindo de dados. Manter o
            // conjunto completo evita ícones "invisíveis" por terem sido
            // removidos no build.
            'fa', 'fas', 'far', 'fab',
            /^fa-/,
            // Classes estruturais de layout definidas em CSS proprio e usadas
            // apenas nos templates PHP. Mantidas explicitamente para que uma
            // falha no scan de conteudo do build nao volte a "sumir" com a
            // navbar, os cards do dashboard ou a centralizacao das paginas.
            'navbar', 'navbar-expand-lg', 'navbar-expand-xl',
            'container-fluid', 'dashboard-card', 'stat-card',
            'user-welcome', 'chart-container', 'chart-wrapper',
            'system-status', 'status-indicator', 'status-online',
            'status-warning', 'status-offline', 'progress-bar-custom',
            'card-icon', 'alert-urgente', 'manutencao-page',
            'login-wrapper', 'login-card', 'login-title', 'login-input',
            'login-btn',
          ],
        },
      }),
      cssnano({ preset: 'default' }),
    ] : []),
  ],
};
