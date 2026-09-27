import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import './tokens.css';

createApp(App).use(createPinia()).mount('#admin-app');
