/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./index.html", "./admin.html"],
  safelist: [
    // JS ile classList.toggle / string birleştirme yoluyla eklenen sınıflar
    "w-6", "w-2", "max-h-0", "max-h-[500px]", "rotate-45", "opacity-0",
    "flex", "hidden", "grid", "overflow-hidden",
    "bg-clay-500", "bg-clay-100", "text-clay-700", "text-clay-800",
    "bg-clay-200/70", "bg-sage-100", "text-sage-600",
    "bg-mustard-100", "text-mustard-600", "bg-ink-200",
  ],
  theme: {
    extend: {
      colors: {
        clay: { 50: "#fdf5f0", 100: "#fae6d9", 200: "#f3c8ab", 300: "#eba576", 400: "#e28449", 500: "#d1652c", 600: "#b04f20", 700: "#8c3e1c", 800: "#70331b", 900: "#5c2b19" },
        ink: { 50: "#f4f5f6", 100: "#e4e6e9", 200: "#c3c8cf", 300: "#9aa2ad", 400: "#6b7480", 500: "#4c5561", 600: "#3a414c", 700: "#2b303a", 800: "#1d2129", 900: "#14171d" },
        sage: { 50: "#f2f6f3", 100: "#dfeadf", 500: "#5c8a6b", 600: "#476d54" },
        mustard: { 100: "#f6e8c4", 200: "#eed69a", 300: "#e2bd63", 500: "#c99a2e", 600: "#a97c20" },
      },
      fontFamily: {
        display: ['"Fraunces"', "serif"],
        sans: ['"Manrope"', "sans-serif"],
      },
      boxShadow: {
        soft: "0 2px 8px -2px rgba(28,22,15,0.08), 0 1px 2px -1px rgba(28,22,15,0.06)",
        lifted: "0 20px 40px -12px rgba(176,79,32,0.22), 0 8px 16px -8px rgba(28,22,15,0.12)",
        card: "0 1px 3px 0 rgba(28,22,15,0.07), 0 1px 2px -1px rgba(28,22,15,0.06)",
        floating: "0 16px 32px -12px rgba(28,22,15,0.22), 0 6px 12px -6px rgba(28,22,15,0.10)",
        vault: "0 32px 64px -12px rgba(10,14,20,0.45), 0 12px 24px -8px rgba(10,14,20,0.25)",
      },
    },
  },
  plugins: [],
};
