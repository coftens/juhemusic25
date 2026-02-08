using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using Avalonia.Platform.Storage;
using MusicLyricApp.Core.Utils;
using MusicLyricApp.Models;
using MusicLyricApp.ViewModels;
using NLog;

namespace MusicLyricApp.Core.Service;

public class StorageService : IStorageService
{
    private ISearchService _searchService;

    private readonly Logger _logger = LogManager.GetCurrentClassLogger();
    
    public void SetSearchService(ISearchService searchService)
    {
        _searchService = searchService;
    }

    public SettingBean ReadAppConfig()
    {
        if (File.Exists(Constants.GetConfigFilePath()))
        {
            var text = File.ReadAllText(Constants.GetConfigFilePath());
            return text.ToEntity<SettingBean>();
        }
        else
        {
            return new SettingBean();
        }
    }

    public void SaveConfig(SettingBean settingBean)
    {
        var path = Constants.GetConfigFilePath();
        File.WriteAllText(path, settingBean.ToJson(), Encoding.UTF8);
        _logger.Info("Save config into {Path}", path);
    }

    public Task<string> SaveResult(SearchResultViewModel searchResult, SettingBean settingBean,
        IWindowProvider windowProvider)
    {
        switch (searchResult.SaveVoMap.Count)
        {
            case 0:
                throw new MusicLyricException(ErrorMsgConst.MUST_SEARCH_BEFORE_SAVE);
            case 1:
                return SaveSingleResult(searchResult, settingBean, windowProvider);
            default:
                return SaveBatchResult(searchResult, settingBean, windowProvider);
        }
    }

    public string SaveSongLink(SearchResultViewModel searchResult, SettingBean settingBean,
        IWindowProvider windowProvider)
    {
        var musicApi = _searchService.GetMusicApi(settingBean.Param.SearchSource);

        switch (searchResult.SaveVoMap.Count)
        {
            case 0:
                throw new MusicLyricException(ErrorMsgConst.MUST_SEARCH_BEFORE_GET_SONG_URL);
            case 1:
                var link = musicApi.GetSongLink(searchResult.SaveVoMap.Keys.First());
                if (link.IsSuccess())
                {
                    windowProvider.SetTextAsync(link.Data);
                    return ErrorMsgConst.SONG_URL_GET_SUCCESS;
                }
                else
                {
                    return link.ErrorMsg;
                }
            default:
                var csv = new CsvBean();
                csv.AddColumn("id");
                csv.AddColumn("songLink");

                foreach (var songId in searchResult.SaveVoMap.Values.Select(saveVo => saveVo.SongVo.DisplayId))
                {
                    csv.AddData(songId);
                    csv.AddData(musicApi.GetSongLink(songId).Data);
                    csv.NextLine();
                }

                searchResult.ResetConsoleOutput(csv.ToString());

                return ErrorMsgConst.SUCCESS;
        }
    }

    public string SaveSongPic(SearchResultViewModel searchResult, SettingBean settingBean,
        IWindowProvider windowProvider)
    {
        switch (searchResult.SaveVoMap.Count)
        {
            case 0:
                throw new MusicLyricException(ErrorMsgConst.MUST_SEARCH_BEFORE_GET_SONG_PIC);
            case 1:
                var pic = searchResult.SaveVoMap.Values.First().SongVo.Pics;
                if (string.IsNullOrWhiteSpace(pic))
                {
                    return ErrorMsgConst.SONG_PIC_GET_FAILED;
                }
                else
                {
                    windowProvider.SetTextAsync(pic);
                    return ErrorMsgConst.SONG_PIC_GET_SUCCESS;
                }
            default:
                var csv = new CsvBean();
                csv.AddColumn("id");
                csv.AddColumn("picLink");

                foreach (var saveVo in searchResult.SaveVoMap.Values)
                {
                    csv.AddData(saveVo.SongVo.DisplayId);
                    csv.AddData(saveVo.SongVo.Pics);
                    csv.NextLine();
                }

                searchResult.ResetConsoleOutput(csv.ToString());

                return ErrorMsgConst.SUCCESS;
        }
    }

    private static async Task<string> SaveSingleResult(SearchResultViewModel searchResult, SettingBean settingBean,
        IWindowProvider windowProvider)
    {
        var saveVo = searchResult.SaveVoMap.Values.First();
        var preCheck = IsSkipStorage(saveVo, settingBean);

        if (preCheck != ErrorMsgConst.SUCCESS)
        {
            throw new MusicLyricException(preCheck);
        }

        await WriteToFile(await SelectFolder(windowProvider), saveVo, settingBean);

        return string.Format(ErrorMsgConst.SAVE_COMPLETE, 1, 0);
    }

    private static async Task<string> SaveBatchResult(SearchResultViewModel searchResult, SettingBean settingBean,
        IWindowProvider windowProvider)
    {
        var folder = await SelectFolder(windowProvider);

        var skipRes = new Dictionary<string, string>();
        var successRes = new HashSet<string>();

        foreach (var saveVo in searchResult.SaveVoMap.Values)
        {
            var resKey = $"{saveVo.SongVo.DisplayId}[{saveVo.SongVo.Name}]";

            var preCheck = IsSkipStorage(saveVo, settingBean);

            if (preCheck != ErrorMsgConst.SUCCESS)
            {
                skipRes[resKey] = preCheck;
            }
            else
            {
                await WriteToFile(folder, saveVo, settingBean);
                successRes.Add(resKey);
            }
        }

        searchResult.ResetConsoleOutput(RenderUtils.RenderStorageResult(skipRes, successRes));

        return string.Format(ErrorMsgConst.SAVE_COMPLETE, successRes.Count, skipRes.Count);
    }

    private static string IsSkipStorage(SaveVo saveVo, SettingBean settingBean)
    {
        // 没有歌词内容
        if (saveVo.LyricVo.IsEmpty())
        {
            return ErrorMsgConst.LRC_NOT_EXIST;
        }

        // 纯音乐跳过
        if (saveVo.LyricVo.IsPureMusic() && settingBean.Config.IgnorePureMusicInSave)
        {
            return ErrorMsgConst.PURE_MUSIC_IGNORE_SAVE;
        }

        return ErrorMsgConst.SUCCESS;
    }

    private static async Task<IStorageFolder> SelectFolder(IWindowProvider windowProvider)
    {
        var folders = await windowProvider.OpenFolderPickerAsync(new FolderPickerOpenOptions
        {
            Title = "选择保存目录",
            AllowMultiple = false
        });

        if (folders.Count == 0)
            throw new MusicLyricException(ErrorMsgConst.STORAGE_FOLDER_ERROR);

        return folders[0];
    }

    private static async Task WriteToFile(IStorageFolder folder, SaveVo saveVo, SettingBean settingBean)
    {
        var extension = settingBean.Param.OutputFileFormat.ToDescription().ToLower();
        var encoding = GlobalUtils.GetEncoding(settingBean.Param.Encoding);
        var filename = GlobalUtils.GetOutputName(saveVo, settingBean.Config.OutputFileNameFormat,
            settingBean.Config.SingerSeparator);

        var res = await LyricUtils.GetOutputContent(saveVo.LyricVo, settingBean);

        var isSingle = res.Count == 1;

        for (var i = 0; i < res.Count; i++)
        {
            var fullName = isSingle ? $"{filename}.{extension}" : $"{filename}-{i}.{extension}";
            var file = await folder.CreateFileAsync(fullName);

            await using var stream = await file.OpenWriteAsync();
            await using var writer = new StreamWriter(stream, encoding);

            await writer.WriteAsync(res[i]);
            await writer.FlushAsync();
        }
    }
}