import {defineConfig} from 'vite';
export default defineConfig({publicDir:false,build:{outDir:'public/assets/build',emptyOutDir:true,manifest:true,rollupOptions:{input:'resources/app.js'}},server:{host:'127.0.0.1'}});
