using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Net;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using Avalonia;
using Avalonia.Controls;
using Avalonia.Threading;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using CommunityToolkit.Mvvm.Messaging;
using MsBox.Avalonia;
using MsBox.Avalonia.Enums;
using MusicLyricApp.Core;
using MusicLyricApp.Core.Service;
using MusicLyricApp.Core.Service.Music;
using MusicLyricApp.Core.Utils;
using MusicLyricApp.Models;
using MusicLyricApp.ViewModels.Messages;
using MusicLyricApp.Views;

namespace MusicLyricApp.ViewModels;

public partial class MainWindowViewModel : ViewModelBase
{
    public SearchParamViewModel SearchParamViewModel { get; } = new();

    public SearchResultViewModel SearchResultViewModel { get; } = new();
    
    public SignalLampViewModel LampVm { get; } = new();

    [ObservableProperty] private string _appTitle = "MusicLyricApp v7.2";

    private readonly SearchService _searchService;

    private readonly StorageService _storageService = new();
        
    private readonly IWindowProvider _windowProvider;

    [ObservableProperty] private SettingBean _settingBean;
    
    private SettingWindow? _settingWindow;
    
    private BlurSearchWindow? _blurSearchWindow;

    public MainWindowViewModel(IWindowProvider windowProvider)
    {
        _windowProvider = windowProvider;

        _settingBean = _storageService.ReadAppConfig();

        _searchService = new SearchService(_settingBean);
        
        _storageService.SetSearchService(_searchService);

        SearchParamViewModel.Bind(_settingBean.Param);
        
        WeakReferenceMessenger.Default.Register<BlurSearchResultsMessage>(this, (r, m) =>
        {
            _ = ProcessBlurSearchResults(m.Value);
        });
        
        WeakReferenceMessenger.Default.Register<CloseWindowMessage>(this, (r, m) =>
        {
            if (m.Value == "BlurSearchWindow" && _blurSearchWindow != null)
            {
                _blurSearchWindow.Close();
                _blurSearchWindow = null;
            }
        });
        
        UpdateTheme();
        
        if (_settingBean.Config.AutoCheckUpdate)
        {
            ThreadPool.QueueUserWorkItem(p => _ = DoVersionCheck(false));
        }
    }
    
    private async Task ProcessBlurSearchResults(string ids)
    {
        SearchParamViewModel.SearchText = ids;
        await ExecuteSearchAsync();
    }

    public void SaveConfig()
    {
        _storageService.SaveConfig(SettingBean);
    }

    [RelayCommand]
    private async Task ExecuteSearchAsync()
    {
        try
        {
            _searchService.InitSongIds(SearchParamViewModel, SettingBean);

            var songIds = SearchParamViewModel.SongIds;
            if (songIds.Count == 0)
            {
                throw new MusicLyricException(ErrorMsgConst.SEARCH_RESULT_EMPTY);
            }

            var result = _searchService.SearchSongs(SearchParamViewModel.SongIds, SettingBean);
            LampVm.UpdateLampInfo(result, SettingBean);
            await _searchService.RenderSearchResult(SearchParamViewModel, SearchResultViewModel, SettingBean, result);
        }
        catch (Exception ex)
        {
            await DialogHelper.ShowMessage(ex);
        }
    }

    [RelayCommand]
    private async Task ExecuteBlurSearchAsync()
    {
        try
        {
            var resultVos = _searchService.BlurSearch(SearchParamViewModel, SettingBean);

            if (_blurSearchWindow != null)
            {
                if (!_blurSearchWindow.IsVisible)
                {
                    // 窗口已经被关闭或隐藏
                    _blurSearchWindow = null;
                }
                else
                {
                    // 窗口还在：如果最小化，恢复正常；否则激活
                    if (_blurSearchWindow.WindowState == WindowState.Minimized)
                    {
                        _blurSearchWindow.WindowState = WindowState.Normal;
                    }

                    _blurSearchWindow.Activate();
                    return;
                }
            }

            // 创建新窗口
            _blurSearchWindow = new BlurSearchWindow(resultVos);
            _blurSearchWindow.Closed += (_, _) => _blurSearchWindow = null;
            _blurSearchWindow.Show();
        }
        catch (Exception ex)
        {
            await DialogHelper.ShowMessage(ex);
        }
    }

    [RelayCommand]
    private async Task ExecuteSaveAsync()
    {
        try
        {
            var message = await _storageService.SaveResult(SearchResultViewModel, SettingBean, _windowProvider);
            await DialogHelper.ShowMessage(message);
        }
        catch (Exception ex)
        {
            if (ex.Message != ErrorMsgConst.STORAGE_FOLDER_ERROR)
            {
                await DialogHelper.ShowMessage(ex);
            }
        }
    }

    [RelayCommand]
    private async Task ExecuteSongLinkAsync()
    {
        try
        {
            var message = _storageService.SaveSongLink(SearchResultViewModel, SettingBean, _windowProvider);
            if (message != ErrorMsgConst.SUCCESS)
            {
                await DialogHelper.ShowMessage(message);
            }
        }
        catch (Exception ex)
        {
            await DialogHelper.ShowMessage(ex);
        }
    }

    [RelayCommand]
    private async Task ExecuteSongPicAsync()
    {
        try
        {
            var message = _storageService.SaveSongPic(SearchResultViewModel, SettingBean, _windowProvider);
            if (message != ErrorMsgConst.SUCCESS)
            {
                await DialogHelper.ShowMessage(message);
            }
        }
        catch (Exception ex)
        {
            await DialogHelper.ShowMessage(ex);
        }
    }

    [RelayCommand]
    private static void ExecuteHome()
    {
        Process.Start(new ProcessStartInfo
        {
            FileName = "https://github.com/jitwxs/163MusicLyrics",
            UseShellExecute = true
        });
    }
    
    [RelayCommand]
    private static void ExecuteWiki()
    {
        Process.Start(new ProcessStartInfo
        {
            FileName = "https://github.com/jitwxs/163MusicLyrics/wiki",
            UseShellExecute = true
        });
    }
    
    [RelayCommand]
    private static void ExecuteIssue()
    {
        Process.Start(new ProcessStartInfo
        {
            FileName = "https://github.com/jitwxs/163MusicLyrics/issues",
            UseShellExecute = true
        });
    }

    [RelayCommand]
    private async Task ExecuteVersionCheckAsync()
    {
        await DoVersionCheck(true);
    }
    
    [RelayCommand]
    private async Task ExecuteShortCutAsync()
    {
        var sb = new StringBuilder();
        sb
            .Append("精确搜索: \t Enter").Append(Environment.NewLine)
            .Append("模糊搜索: \t Ctrl + Enter").Append(Environment.NewLine)
            .Append("保存结果: \t Ctrl + S").Append(Environment.NewLine);
        
        await DialogHelper.ShowMessage(sb.ToString());
    }

    [RelayCommand]
    private void ExecuteSetting()
    {
        if (_settingWindow != null)
        {
            if (!_settingWindow.IsVisible)
            {
                // 窗口已经被关闭或隐藏
                _settingWindow = null;
            }
            else
            {
                // 窗口还在：如果最小化，恢复正常；否则激活
                if (_settingWindow.WindowState == WindowState.Minimized)
                {
                    _settingWindow.WindowState = WindowState.Normal;
                }

                _settingWindow.Activate();
                return;
            }
        }

        // 创建新窗口
        _settingWindow = new SettingWindow(SettingBean);
        _settingWindow.Closed += (_, _) => 
        {
            if (_settingWindow.DataContext is SettingViewModel vm)
            {
                vm.OnClosing();
                UpdateTheme();
            }
            _settingWindow = null;
        };
        _settingWindow.Show();
    }
    
    private bool _inCheckVersion;
    
    private bool _showMessageIfNotExistLatestVersion;
    
    private async Task DoVersionCheck(bool showMessageIfNotExistLatestVersion)
    {
        _showMessageIfNotExistLatestVersion = showMessageIfNotExistLatestVersion;
        
        if (_inCheckVersion)
        {
            return;
        }
        
        _inCheckVersion = true;
        
        try
        {
            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;

            var info = HttpUtils.HttpGet<GitHubInfo>(
                "https://api.github.com/repos/jitwxs/163MusicLyrics/releases/latest", 
                "application/json", 
                new Dictionary<string, string>
                {
                    { "Accept", "application/vnd.github.v3+json" },
                    { "User-Agent", BaseNativeApi.Useragent }
                });
            
            if (info == null)
            {
                throw new MusicLyricException(ErrorMsgConst.GET_LATEST_VERSION_FAILED);
            }
            if (info.Message != null && info.Message.Contains("API rate limit"))
            {
                throw new MusicLyricException(ErrorMsgConst.API_RATE_LIMIT);
            }

            var curMatch = VersionRegex().Match(AppTitle);
            var curBigV = int.Parse(curMatch.Groups[1].Value);
            var curSmallV = int.Parse(curMatch.Groups[2].Value);
            
            var originMatch = VersionRegex().Match(info.TagName);
            var bigV = int.Parse(originMatch.Groups[1].Value);
            var smallV = int.Parse(originMatch.Groups[2].Value);

            if (bigV > curBigV || (bigV == curBigV && smallV > curSmallV))
            {
                var sb = new StringBuilder();
                sb
                    .Append($"Tag: {info.TagName}").Append('\t')
                    .Append($"UpdateTime: {info.PublishedAt.DateTime.AddHours(8)}").Append('\t')
                    .Append($"DownloadCount: {info.Assets[0].DownloadCount}").Append('\t')
                    .Append($"Author: {info.Author.Login}")
                    .Append(Environment.NewLine)
                    .Append(Environment.NewLine)
                    .Append(info.Body);
                
                await Dispatcher.UIThread.InvokeAsync(async () =>
                {
                    var box = MessageBoxManager.GetMessageBoxStandard("更新说明", sb.ToString(), ButtonEnum.YesNo);
                    var result = await box.ShowWindowAsync();
    
                    if (result == ButtonResult.Yes)
                    {
                        Process.Start(new ProcessStartInfo
                        {
                            FileName = "https://github.com/jitwxs/163MusicLyrics/releases",
                            UseShellExecute = true
                        });
                    }
                });
            }
            else if (_showMessageIfNotExistLatestVersion)
            {
                throw new MusicLyricException(ErrorMsgConst.THIS_IS_LATEST_VERSION);
            }
        }
        catch (Exception ex)
        {
            await DialogHelper.ShowMessage(ex);
        }
        finally
        {
            _inCheckVersion = false;
        }
    }

    [GeneratedRegex(@"v(\d+)\.(\d+)")]
    private static partial Regex VersionRegex();

    private void UpdateTheme()
    {
        ((App)Application.Current!).SetTheme(SettingBean.Config.ThemeMode);
    }
}