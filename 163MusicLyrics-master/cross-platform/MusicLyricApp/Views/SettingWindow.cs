using Avalonia.Controls;
using MusicLyricApp.Models;
using MusicLyricApp.ViewModels;

namespace MusicLyricApp.Views;

public class SettingWindow : Window
{
    public SettingWindow(SettingBean settingBean)
    {
        Width = 600;
        Height = 700;
        Title = "设置";
        
        Content = new SettingView();
        DataContext = new SettingViewModel(settingBean);
        Icon = Constants.GetIcon("settings");
    }
}