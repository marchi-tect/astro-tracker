// AstroTracker Color Themes
// Each theme defines CSS custom property overrides applied to :root
const THEMES = {
  dark: {
    label: 'Dark',
    vars: {
      '--bg':        '#0a0c14',
      '--bg2':       '#111320',
      '--bg3':       '#181b2e',
      '--bg4':       '#1e2235',
      '--border':    '#2a2f4a',
      '--border2':   '#343a5c',
      '--text':      '#d4d8f0',
      '--text2':     '#8890b0',
      '--text3':     '#5c6080',
      '--accent':    '#5e7bf8',
      '--accent2':   '#4a66e0',
      '--green':     '#3dd68c',
      '--red':       '#f25c5c',
      '--amber':     '#f5a623',
      '--teal':      '#39d98a',
      '--gold':      '#ffd700',
      '--c-galaxy':     '#9b6dff',
      '--c-emission':   '#f25c5c',
      '--c-reflection': '#4f8ef7',
      '--c-dark':       '#8892b0',
      '--c-planetary':  '#39d98a',
      '--c-snr':        '#f5a623',
      '--c-globular':   '#ffd700',
      '--c-open':       '#ff9f43',
    }
  },
  midnight: {
    label: 'Midnight',
    vars: {
      '--bg':        '#05070f',
      '--bg2':       '#090c1a',
      '--bg3':       '#0e1126',
      '--bg4':       '#131630',
      '--border':    '#1c2040',
      '--border2':   '#252a50',
      '--text':      '#c8cde8',
      '--text2':     '#6e779a',
      '--text3':     '#454870',
      '--accent':    '#7c9bff',
      '--accent2':   '#6080e8',
      '--green':     '#2ec87a',
      '--red':       '#e84040',
      '--amber':     '#e8960e',
      '--teal':      '#28c878',
      '--gold':      '#ffc700',
      '--c-galaxy':     '#8866ff',
      '--c-emission':   '#e84040',
      '--c-reflection': '#3a7eef',
      '--c-dark':       '#7880a8',
      '--c-planetary':  '#28c878',
      '--c-snr':        '#e8960e',
      '--c-globular':   '#ffc700',
      '--c-open':       '#ee8830',
    }
  },
  warm: {
    label: 'Warm Dark',
    vars: {
      '--bg':        '#100c08',
      '--bg2':       '#1c1510',
      '--bg3':       '#261e18',
      '--bg4':       '#2e2620',
      '--border':    '#3e3028',
      '--border2':   '#504038',
      '--text':      '#eedfc8',
      '--text2':     '#a08868',
      '--text3':     '#706050',
      '--accent':    '#e8a040',
      '--accent2':   '#d08820',
      '--green':     '#80d860',
      '--red':       '#e85050',
      '--amber':     '#e8b020',
      '--teal':      '#60d880',
      '--gold':      '#ffd020',
      '--c-galaxy':     '#c06860',
      '--c-emission':   '#e85050',
      '--c-reflection': '#6090e0',
      '--c-dark':       '#908070',
      '--c-planetary':  '#60d880',
      '--c-snr':        '#e8b020',
      '--c-globular':   '#ffd020',
      '--c-open':       '#e09040',
    }
  },
  light: {
    label: 'Light',
    vars: {
      '--bg':        '#f0f2f8',
      '--bg2':       '#ffffff',
      '--bg3':       '#e8eaf2',
      '--bg4':       '#dde0ee',
      '--border':    '#c8ccdf',
      '--border2':   '#aab0cc',
      '--text':      '#1a1e34',
      '--text2':     '#4a5078',
      '--text3':     '#8088a8',
      '--accent':    '#3355e8',
      '--accent2':   '#2244cc',
      '--green':     '#1a9e58',
      '--red':       '#cc2020',
      '--amber':     '#c07010',
      '--teal':      '#1a9e58',
      '--gold':      '#a07810',
      '--c-galaxy':     '#6633cc',
      '--c-emission':   '#cc2020',
      '--c-reflection': '#1a4ec8',
      '--c-dark':       '#556080',
      '--c-planetary':  '#1a9e58',
      '--c-snr':        '#c07010',
      '--c-globular':   '#a07810',
      '--c-open':       '#c06010',
    }
  }
};

function applyTheme(name) {
  const theme = THEMES[name] || THEMES.dark;
  const root = document.documentElement;
  Object.entries(theme.vars).forEach(([k, v]) => root.style.setProperty(k, v));
  try { localStorage.setItem('astro-theme', name); } catch(e) {}
}

function loadSavedTheme() {
  try {
    const saved = localStorage.getItem('astro-theme');
    if (saved && THEMES[saved]) applyTheme(saved);
  } catch(e) {}
}
