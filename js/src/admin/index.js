import app from 'flarum/app';
import MybbToFlarumPage from './components/MybbToFlarumPage';

app.initializers.add('lukastrkan-mybb-to-flarum', () => {
    app.extensionData.for('lukastrkan-mybb-to-flarum').registerPage(MybbToFlarumPage);
});