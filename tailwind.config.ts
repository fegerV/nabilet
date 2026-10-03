import type { Config } from 'tailwindcss'

/**
 * Дизайн-токены NABILET.
 *
 * Направление — «яркий потребительский»: насыщенный электрик-фиолетовый как
 * основной бренд-цвет, тёплый коралловый как цвет покупки, плюс жёстко
 * закреплённая семантика состояний (заказ, платёж, билет, место в зале).
 *
 * Цвета статусов не «на вкус дизайнера»: они совпадают с машинами состояний из
 * docs/STATE-MACHINES.md, поэтому один и тот же статус выглядит одинаково
 * в списке заказов, в карточке билета и в чек-ине.
 */
const config: Config = {
  content: [
    './resources/**/*.{vue,js,ts,blade.php}',
    './index.html',
    './app/Modules/**/resources/views/**/*.blade.php',
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        /* Бренд: электрик-фиолетовый. Действие, навигация, выбранное место. */
        brand: {
          50: '#F2F0FF',
          100: '#E7E2FF',
          200: '#CFC5FF',
          300: '#B0A0FF',
          400: '#8E74FF',
          500: '#6D4AFF',
          600: '#5A31F0',
          700: '#4921C9',
          800: '#3B1BA1',
          900: '#2F1780',
          950: '#1D0E52',
        },
        /* Акцент: коралл. Покупка, деньги, срочность, дедлайн. */
        accent: {
          50: '#FFF4EF',
          100: '#FFE5D9',
          200: '#FFC7AD',
          300: '#FFA37C',
          400: '#FF7F4D',
          500: '#FF5C22',
          600: '#F03F00',
          700: '#C63200',
          800: '#9C2A05',
          900: '#7D250A',
        },
        /* Ожидание: hold, таймер, «место придержано». */
        sun: { 100: '#FFF3D6', 200: '#FFE4A8', 300: '#FFD470', 400: '#FFC53D', 500: '#FFB020', 600: '#E59400', 700: '#B87200' },
        /* Успех: оплачено, билет действителен, вход разрешён. */
        mint: { 100: '#D6FBF0', 200: '#A5F5DE', 300: '#66EBC6', 400: '#2FE0A8', 500: '#00C48C', 600: '#009E72', 700: '#007A58' },
        /* Опасность: ошибка, отмена, возврат, «билет уже использован». */
        rose: { 100: '#FFE0E6', 200: '#FFC0CC', 300: '#FF94A9', 400: '#FF6B85', 500: '#FF3B5C', 600: '#E31C42', 700: '#B31233' },
        /* Информация: подсказки, статусы обработки. */
        sky: { 100: '#DCEEFF', 200: '#B7DDFF', 300: '#8AC8FF', 400: '#5CB8FF', 500: '#2AA3FF', 600: '#0B82E0', 700: '#0A66AE' },
        /* Нейтральная шкала: тёмная база «сцена». */
        ink: {
          50: '#F6F5FA',
          100: '#E8E5F1',
          200: '#CBC6DC',
          300: '#A49DBF',
          400: '#7A729F',
          500: '#554D80',
          600: '#3B3468',
          700: '#2A2450',
          800: '#1D1840',
          850: '#171331',
          900: '#120F24',
          950: '#0B0918',
        },
        /* VIP / премиум-сектор. */
        gold: { 300: '#FFE08A', 400: '#FFD05C', 500: '#F5B417', 600: '#D19500' },
      },
      fontFamily: {
        sans: ['Inter', 'Manrope', 'Segoe UI', 'system-ui', '-apple-system', 'sans-serif'],
        display: ['Manrope', 'Inter', 'Segoe UI', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'Cascadia Mono', 'Consolas', 'monospace'],
      },
      fontSize: {
        '2xs': ['0.6875rem', { lineHeight: '1rem', letterSpacing: '0.02em' }],
        xs: ['0.75rem', { lineHeight: '1.125rem' }],
        sm: ['0.8125rem', { lineHeight: '1.25rem' }],
        base: ['0.9375rem', { lineHeight: '1.5rem' }],
        lg: ['1.0625rem', { lineHeight: '1.625rem' }],
        xl: ['1.25rem', { lineHeight: '1.75rem' }],
        '2xl': ['1.5rem', { lineHeight: '1.9rem' }],
        '3xl': ['1.875rem', { lineHeight: '2.2rem', letterSpacing: '-0.02em' }],
        '4xl': ['2.375rem', { lineHeight: '2.6rem', letterSpacing: '-0.025em' }],
        '5xl': ['3rem', { lineHeight: '3.2rem', letterSpacing: '-0.03em' }],
        '6xl': ['3.75rem', { lineHeight: '3.9rem', letterSpacing: '-0.035em' }],
      },
      borderRadius: {
        sm: '6px',
        DEFAULT: '10px',
        md: '12px',
        lg: '16px',
        xl: '20px',
        '2xl': '26px',
        '3xl': '34px',
      },
      boxShadow: {
        /* Слои глубины: от «плоско на поверхности» до «парит над витриной». */
        xs: '0 1px 2px rgb(11 9 24 / 0.06)',
        sm: '0 2px 6px -1px rgb(11 9 24 / 0.10)',
        md: '0 8px 24px -6px rgb(11 9 24 / 0.16)',
        lg: '0 18px 48px -12px rgb(11 9 24 / 0.24)',
        xl: '0 32px 72px -20px rgb(11 9 24 / 0.34)',
        /* Свечение под бренд-CTA: единственная «эмоция» в интерфейсе. */
        brand: '0 10px 30px -8px rgb(109 74 255 / 0.55)',
        accent: '0 10px 30px -8px rgb(255 92 34 / 0.55)',
        'inner-line': 'inset 0 0 0 1px rgb(255 255 255 / 0.06)',
      },
      transitionTimingFunction: {
        /* Ощущение «отзывчиво»: быстрый старт, мягкое торможение. */
        out: 'cubic-bezier(0.22, 1, 0.36, 1)',
        'in-out': 'cubic-bezier(0.65, 0, 0.35, 1)',
      },
      transitionDuration: { 120: '120ms', 200: '200ms', 320: '320ms' },
      keyframes: {
        'fade-up': { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'none' } },
        'fade-in': { from: { opacity: '0' }, to: { opacity: '1' } },
        'sheet-up': { from: { transform: 'translateY(100%)' }, to: { transform: 'translateY(0)' } },
        'scale-in': { from: { opacity: '0', transform: 'scale(0.96)' }, to: { opacity: '1', transform: 'none' } },
        shimmer: { '100%': { transform: 'translateX(100%)' } },
        'pulse-ring': {
          '0%': { boxShadow: '0 0 0 0 rgb(109 74 255 / 0.45)' },
          '70%': { boxShadow: '0 0 0 10px rgb(109 74 255 / 0)' },
          '100%': { boxShadow: '0 0 0 0 rgb(109 74 255 / 0)' },
        },
        /* Пульсация метки места, которое держит другой покупатель. */
        'hold-blink': { '0%,100%': { opacity: '1' }, '50%': { opacity: '0.55' } },
      },
      animation: {
        'fade-up': 'fade-up 320ms cubic-bezier(0.22, 1, 0.36, 1) both',
        'fade-in': 'fade-in 200ms ease-out both',
        'sheet-up': 'sheet-up 320ms cubic-bezier(0.22, 1, 0.36, 1) both',
        'scale-in': 'scale-in 200ms cubic-bezier(0.22, 1, 0.36, 1) both',
        shimmer: 'shimmer 1.4s infinite',
        'pulse-ring': 'pulse-ring 1.8s infinite',
        'hold-blink': 'hold-blink 1.6s ease-in-out infinite',
      },
      backgroundImage: {
        'brand-gradient': 'linear-gradient(135deg, #6D4AFF 0%, #8E74FF 45%, #FF5C22 130%)',
        'accent-gradient': 'linear-gradient(135deg, #FF5C22 0%, #FF7F4D 60%, #FFC53D 120%)',
        'gold-gradient': 'linear-gradient(135deg, #FFD05C 0%, #F5B417 100%)',
        /* Сцена: подсветка зала сверху — узнаваемый силуэт любого концертного зала. */
        stage: 'radial-gradient(120% 100% at 50% 0%, rgb(109 74 255 / 0.35) 0%, transparent 62%)',
        'seat-stripes': 'repeating-linear-gradient(45deg, currentColor 0 2px, transparent 2px 6px)',
      },
      spacing: { 18: '4.5rem', 22: '5.5rem', 30: '7.5rem' },
      maxWidth: { content: '1240px', prose: '68ch' },
      zIndex: { sheet: '60', modal: '70', toast: '80', palette: '90' },
    },
  },
  plugins: [],
}

export default config
